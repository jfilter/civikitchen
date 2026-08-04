<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The `.ckconform` policy file: its parser, and the inventory of what may be
 * in it.
 *
 * ONE parser, because there were seven. Two in PHP (Context and ckinit) and
 * five in shell, and the shell ones disagreed with each other and with PHP on
 * every edge the format has: ckcoverage matched `^min_coverage=` with sed, so
 * an indented line that PHP accepted was invisible to it and the coverage
 * floor silently disappeared; ckmutate ran `tr -d ' \t'` over the value, which
 * would have mangled any value with meaningful spaces; ckrelease was the only
 * reader that stripped the mandatory ` -- <reason>`, so `min_coverage=70 -- …`
 * reached ckcoverage's numeric comparison as a sentence.
 *
 * The tool already learned this lesson once. ckconform's own shim says so: the
 * checks were rewritten from shell to PHP "after a run of bugs that were all
 * the same bug: parsing structured formats with line-oriented text tools". The
 * policy file was the last place still doing it.
 *
 * The shell side now reads this through `ckconform --policy-env` and
 * `ckconform --policy <key>` and parses nothing.
 *
 * FORMAT: `KEY=VALUE` per line, `#` comments and blank lines ignored, leading
 * and trailing whitespace trimmed, first occurrence of a scalar key wins. The
 * ` -- <reason>` suffix is part of the VALUE — several checks match on it, and
 * the doctrine that an exception without a reason is indistinguishable from a
 * stale one is enforced by the checks that own each key, not here.
 */
final class Policy
{
    /**
     * Every key `.ckconform` may carry, and who reads it.
     *
     * This inventory is the point of the class. Before it existed no single
     * place knew all the keys: ckconform knew nine, the ck* tools brought six
     * more, ckinit one — so `min_covrage=70` disabled a coverage floor in
     * silence while a typo'd check name in an inline `ckconform-ignore` was
     * reported as "a dead ignore never matches". Same class of mistake, and
     * only one of them was caught. PolicyKeyCheck closes that gap using this
     * list, which means a new key must be added HERE as well as read.
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
     * Only one so far. `template_custom` looks like a member and is not: its
     * value is a comma-separated list on ONE line, and ckinit stops at the
     * first occurrence — so a second `template_custom=` line does nothing at
     * all today. That is exactly the silence this class exists to end, so it
     * stays scalar and PolicyKeyCheck reports the repeat, rather than the
     * behaviour changing under repos that may already have one.
     *
     * @var list<string>
     */
    public const REPEATABLE = ['lifecycle_log_ignore'];

    /**
     * Keys whose value must be a whole percentage. Nothing validated these
     * before, so `min_coverage=seventy` reached a numeric comparison as a
     * string and the floor quietly became unenforceable.
     *
     * @var list<string>
     */
    public const PERCENT = ['min_coverage', 'mutation_min_msi', 'mutation_min_covered_msi'];

    /**
     * Parse the file's contents. Unknown keys are returned like any other —
     * reporting them is PolicyKeyCheck's job, and a parser that dropped them
     * would leave that check nothing to see.
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
     * The value without its ` -- <reason>` suffix.
     *
     * For the shell view only. Inside ckconform the reason stays part of the
     * value, because checks match on it (TestSuiteRequiredCheck accepts
     * `optional -- <reason>` and nothing else); stripping it there would turn
     * a mandatory reason into an optional one.
     */
    public static function stripReason(string $value): string
    {
        $cut = strpos($value, ' -- ');

        return $cut === false ? $value : rtrim(substr($value, 0, $cut));
    }

    /**
     * `CK_POLICY_<KEY>='<value>'` lines for `eval` in a shell tool.
     *
     * Scalar keys only, reason stripped: a shell caller wants `70`, not
     * `70 -- measured 2026-01`. Repeatable keys are not representable as a
     * scalar and are fetched one per line with `--policy <key>` instead, which
     * keeps the reason intact for the callers that must validate it.
     *
     * escapeshellarg, not quotes-by-hand: values are free text from a repo.
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
