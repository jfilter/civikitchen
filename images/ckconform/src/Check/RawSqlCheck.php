<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Raw SQL wants a reason.
 *
 * The sink list is deliberately narrow — the provable low-level executors on
 * CRM_Core_DAO. Not on it: the CRM_Utils_SQL_* builders (they parameterise),
 * compose-only helpers (toSQL, interpolate), and `->query()` on arbitrary
 * receivers (not statically provable). Upgraders are exempt from the
 * justification duty: the upgrade framework's own guidance recommends
 * low-level SQL there, because APIs and BAOs are unstable mid-upgrade.
 *
 * Two findings, both WARN, both escaped by `ckconform-ignore raw-sql -- <reason>`:
 *  - a sink call: say why APIv4 / the BAO / a SQL builder cannot express it —
 *    the ignore's reason IS the justification, and it is not optional;
 *  - a sink call whose SQL argument visibly interpolates or concatenates
 *    variables: bind via %1 parameters instead. This one fires in upgraders
 *    too — composition risk does not pause during upgrades. A token scan
 *    cannot prove the variable is tainted, so this stays a WARN, not a FAIL.
 */
final class RawSqlCheck implements Check
{
    private const SINKS = ['executeQuery', 'executeUnbufferedQuery', 'singleValueQuery'];

    public function name(): string
    {
        return 'raw-sql';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'CRM_Core_DAO::')) {
                continue;
            }
            $suppressions = Suppressions::of($contents);
            $isUpgrader = str_contains($file, 'Upgrader');

            foreach ($this->sinkCalls($contents) as [$method, $line, $interpolates]) {
                // Suppression last: a plain sink in an upgrader is no finding,
                // and consulting the ignore there would mark it consumed and
                // hide SuppressionHygieneCheck's unused-ignore report.
                if ((!$interpolates && $isUpgrader) || $suppressions->suppressed($this->name(), $line)) {
                    continue;
                }
                if ($interpolates) {
                    $reporter->warn(sprintf(
                        '%s:%d: CRM_Core_DAO::%s() interpolates variables into the SQL string — bind values via %%1 parameters, or justify the composition (ckconform-ignore raw-sql -- <reason>)',
                        $file,
                        $line,
                        $method,
                    ));
                } else {
                    $reporter->warn(sprintf(
                        '%s:%d: CRM_Core_DAO::%s() — raw SQL needs a reason: say why APIv4, the BAO or a CRM_Utils_SQL builder cannot express this (ckconform-ignore raw-sql -- <reason>)',
                        $file,
                        $line,
                        $method,
                    ));
                }
            }
        }
    }

    /**
     * Sink calls with their line and whether the first argument visibly builds
     * SQL out of variables: a variable inside a double-quoted/heredoc string,
     * or an argument-level concatenation mixing literals and variables. A bare
     * `$sql` variable is NOT flagged as interpolation — where it was built is
     * beyond a token scan.
     *
     * @return list<array{string, int, bool}> [method, line, interpolates]
     */
    private function sinkCalls(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $calls = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)
                || !in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)
                || ltrim($token[1], '\\') !== 'CRM_Core_DAO'
            ) {
                continue;
            }
            $j = $this->nextMeaningful($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== \T_DOUBLE_COLON) {
                continue;
            }
            $k = $this->nextMeaningful($tokens, $j + 1);
            if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== \T_STRING
                || !in_array($tokens[$k][1], self::SINKS, true)
            ) {
                continue;
            }
            $calls[] = [$tokens[$k][1], $tokens[$k][2], $this->firstArgInterpolates($tokens, $k + 1)];
        }

        return $calls;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function firstArgInterpolates(array $tokens, int $start): bool
    {
        $n = count($tokens);
        $i = $this->nextMeaningful($tokens, $start);
        if ($i === null || $tokens[$i] !== '(') {
            return false;
        }

        $depth = 1;
        $inString = false;
        $sawLiteral = false;
        $sawConcat = false;
        $sawVariable = false;
        for ($i++; $i < $n && $depth > 0; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '(') {
                    $depth++;
                } elseif ($token === ')') {
                    $depth--;
                } elseif ($token === ',' && $depth === 1) {
                    break;
                } elseif ($token === '"') {
                    $inString = !$inString;
                    $sawLiteral = true;
                } elseif ($token === '.' && !$inString) {
                    $sawConcat = true;
                }
                continue;
            }

            switch ($token[0]) {
                case \T_START_HEREDOC:
                    $inString = true;
                    $sawLiteral = true;
                    break;
                case \T_END_HEREDOC:
                    $inString = false;
                    break;
                case \T_CONSTANT_ENCAPSED_STRING:
                    $sawLiteral = true;
                    break;
                case \T_VARIABLE:
                    if ($inString) {
                        return true;
                    }
                    $sawVariable = true;
                    break;
                case \T_CURLY_OPEN:
                case \T_DOLLAR_OPEN_CURLY_BRACES:
                    if ($inString) {
                        return true;
                    }
                    break;
            }
        }

        return $sawConcat && $sawLiteral && $sawVariable;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextMeaningful(array $tokens, int $start): ?int
    {
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
