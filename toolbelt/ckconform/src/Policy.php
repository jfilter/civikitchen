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
        'known_api4_entities' => 'ckconform: APIv4 entities supplied by required third-party extensions, reason mandatory',
        'bundles' => 'ckconform: vendored front-end bundles that are not repo source',
        'vendored_paths' => 'cklint + ckfmt + ckeslint: third-party source this repo carries verbatim, reason mandatory',
        'smarty_skip_templates' => 'cksmarty: managed MessageTemplates this repo renders without Smarty, reason mandatory',
        'npm_license' => 'ckconform: accepted npm licence identifiers',
        'release' => "ckconform: 'none -- <reason>' for a repo that deliberately cuts no releases",
        'max_unreleased_days' => 'ckconform: days of unreleased shipped changes before it is reported',
        // read by the ck* tools
        'mutation_min_msi' => 'ckmutate: mutation score floor (--min-msi)',
        'mutation_min_covered_msi' => 'ckmutate: covered-code mutation floor (--min-covered-msi)',
        'mutation_paths' => 'ckmutate: what to mutate, comma-separated',
        'dist_exclude' => 'ckrelease + ckconform: additionally kept out of the release zip',
        'dist_include' => 'ckrelease + ckconform: kept IN the zip despite the central exclude list',
        'lifecycle_log_ignore' => 'cklifecycle: log patterns to ignore, reason mandatory',
        // read by ckinit
        'template_custom' => 'ckinit: template-managed files this repo owns instead',
        'renovate_preset' => 'ckinit: the Renovate preset the managed renovate.json extends',
        // read by the image entrypoint (docker/runtime/provision.sh)
        'extension_source' => 'entrypoint: key@URL to download a <requires> dependency the registry does not serve, one per line',
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
    public const REPEATABLE = ['lifecycle_log_ignore', 'vendored_paths', 'smarty_skip_templates', 'extension_source'];

    /** @var list<string> */
    public const PERCENT = ['min_coverage', 'mutation_min_msi', 'mutation_min_covered_msi'];

    /**
     * Environment variable naming an organisation-wide defaults file in the
     * same format. Its keys apply to every repo whose tools see the variable;
     * the repo's own `.ckconform` overrides per key.
     */
    public const DEFAULTS_ENV = 'CK_DEFAULT_POLICY';

    /**
     * The keys a defaults file may set: those read by ckconform and ckinit
     * only. In CI the variable reaches exactly those two runs; a key another
     * gate reads (min_coverage, mutation_*, lifecycle_log_ignore, ...) would
     * apply in one place and silently not in the other, so it stays per repo.
     *
     * @var list<string>
     */
    public const SHARED = ['license', 'npm_license', 'copyright', 'vendor', 'hook_style', 'max_unreleased_days', 'renovate_preset'];

    /**
     * The defaults file's contents, null when the variable is unset. A variable
     * that names a file which cannot be read is an error, not an absent layer:
     * a fleet whose licence policy silently stopped applying is the exact
     * failure the layer exists to prevent.
     */
    public static function defaultsRaw(): ?string
    {
        $file = getenv(self::DEFAULTS_ENV);
        if ($file === false || $file === '') {
            return null;
        }
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            throw new \RuntimeException(self::DEFAULTS_ENV . " names '{$file}', which is not a readable file");
        }

        return $raw;
    }

    /**
     * Defaults overlaid by the repo's own file, per key: a key the repo sets
     * REPLACES the default's values — for repeatable keys too, so a repo's
     * `vendored_paths` lines never silently inherit a fleet-wide one. Keys the
     * repo does not mention keep the default. The defaults' keys come first,
     * so "first occurrence wins" ordering is preserved within each source.
     * Only SHARED keys are taken from the defaults; PolicyKeyCheck reports the
     * others, and dropping them here keeps a gate honest even where it does
     * not run.
     *
     * @return array<string, list<string>>
     */
    public static function layered(?string $repoRaw, ?string $defaultsRaw): array
    {
        $defaults = array_intersect_key(self::parse($defaultsRaw), array_flip(self::SHARED));

        return array_merge($defaults, self::parse($repoRaw));
    }

    /**
     * The view every reader gets: the repo file over the CK_DEFAULT_POLICY
     * layer. Context::policy() and the CLI read-outs go through here, so the
     * merge happens in exactly one place.
     *
     * @return array<string, list<string>>
     */
    public static function effective(?string $repoRaw): array
    {
        return self::layered($repoRaw, self::defaultsRaw());
    }

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
     * Takes the repo file's contents; the defaults layer is applied here.
     */
    public static function toShell(string $raw): string
    {
        $out = '';
        foreach (self::effective($raw) as $key => $values) {
            if (in_array($key, self::REPEATABLE, true) || !isset(self::KEYS[$key])) {
                continue;
            }
            $name = 'CK_POLICY_' . strtoupper($key);
            $out .= $name . '=' . escapeshellarg(self::stripReason($values[0])) . "\n";
        }

        return $out;
    }
}
