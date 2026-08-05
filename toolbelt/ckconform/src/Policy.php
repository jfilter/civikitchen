<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The `.ckconform` policy file: its parser, and the inventory of what may be in
 * it. The only parser — the shell side reads it through
 * `ckconform --policy-env` / `--policy <key>` (there used to be five sed-based
 * readers here, which disagreed on indentation, whitespace and the reason
 * suffix; see README, "One file, one parser").
 *
 * FORMAT: `KEY=VALUE` per line, `#` comments and blank lines ignored,
 * whitespace trimmed, first occurrence of a scalar key wins. The ` -- <reason>`
 * suffix is part of the VALUE — checks match on it, so whether a reason is
 * mandatory is decided by the check that owns each key, not here.
 */
final class Policy
{
    /**
     * Every key `.ckconform` may carry, and who reads it. PolicyKeyCheck
     * reports anything not listed here, so a new key is added HERE as well as
     * read.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        // read by ckconform's own checks
        'ignore_checks' => 'ckconform: skip whole checks, comma-separated',
        'min_coverage' => 'ckconform + ckcoverage: the coverage floor in percent',
        'tests' => "ckconform + ckcoverage: 'optional -- <reason>' for a repo with no PHP suite",
        'license' => 'ckconform: the licence this repo ships under',
        'copyright' => 'ckconform: the copyright holder in file headers',
        'vendor' => 'ckconform: the composer vendor name',
        'hook_style' => 'ckconform: which hook declaration style this repo uses',
        'known_hooks' => 'ckconform: hook names this repo defines itself',
        'bundles' => 'ckconform: vendored front-end bundles that are not repo source',
        'npm_license' => 'ckconform: accepted npm licence identifiers',
        // read by the ck* tools
        'mutation_min_msi' => 'ckmutate: mutation score floor (--min-msi)',
        'mutation_min_covered_msi' => 'ckmutate: covered-code mutation floor (--min-covered-msi)',
        'mutation_paths' => 'ckmutate: what to mutate, comma-separated',
        'dist_exclude' => 'ckrelease: additionally kept out of the release zip',
        'dist_include' => 'ckrelease: kept IN the zip despite the central exclude list',
        'lifecycle_log_ignore' => 'cklifecycle: log patterns to ignore, reason mandatory',
        // read by ckinit
        'template_custom' => 'ckinit: template-managed files this repo owns instead',
    ];

    /**
     * Keys where every occurrence counts, rather than the first one winning.
     *
     * `template_custom` is deliberately NOT one: its value is a comma-separated
     * list on one line and ckinit stops at the first occurrence, so a second
     * line does nothing today. It stays scalar and PolicyKeyCheck reports the
     * repeat, rather than behaviour changing under repos that may have one.
     *
     * @var list<string>
     */
    public const REPEATABLE = ['lifecycle_log_ignore'];

    /** @var list<string> */
    public const PERCENT = ['min_coverage', 'mutation_min_msi', 'mutation_min_covered_msi'];

    /**
     * Unknown keys are returned like any other — reporting them is
     * PolicyKeyCheck's job, and dropping them here would leave it nothing.
     *
     * @return array<string, list<string>> key => values, in file order
     */
    public static function parse(?string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw ?? '') as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $out[$key][] = trim($value);
        }

        return $out;
    }

    /**
     * The lines parse() skips over: not blank, not a comment, but carrying no
     * `KEY=` either — `min_coverage 70` is one, and it never becomes a key, so
     * checking parsed keys alone can never see it.
     *
     * @return array<int, string> 1-based line number => the offending line
     */
    public static function malformed(?string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw ?? '') as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=') || trim(explode('=', $line, 2)[0]) === '') {
                $out[$i + 1] = $line;
            }
        }

        return $out;
    }

    /**
     * The value without its ` -- <reason>` suffix. For the shell view only:
     * inside ckconform the reason stays part of the value, because checks match
     * on it (TestSuiteRequiredCheck accepts `optional -- <reason>` and nothing
     * else) and stripping it would make a mandatory reason optional.
     */
    public static function stripReason(string $value): string
    {
        $cut = strpos($value, ' -- ');

        return $cut === false ? $value : rtrim(substr($value, 0, $cut));
    }

    /**
     * `CK_POLICY_<KEY>='<value>'` lines for `eval` in a shell tool. Scalar keys
     * only, reason stripped; repeatable keys go through `--policy <key>`.
     * escapeshellarg, not quotes by hand: values are free text from a repo.
     */
    public static function toShell(string $raw): string
    {
        $out = '';
        foreach (self::parse($raw) as $key => $values) {
            if (in_array($key, self::REPEATABLE, true) || !isset(self::KEYS[$key])) {
                continue;
            }
            $name = 'CK_POLICY_' . strtoupper($key);
            $out .= $name . '=' . escapeshellarg(self::stripReason($values[0])) . "\n";
        }

        return $out;
    }
}
