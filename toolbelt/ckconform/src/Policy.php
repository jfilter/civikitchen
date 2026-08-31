<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

use Composer\Semver\VersionParser;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The `policy:` section of civikitchen.yaml: its single parser and the
 * inventory of what may be in it. Shell tools read it only through
 * `ckconform --policy-env` / `--policy <key>`.
 *
 * The public YAML uses typed nested objects. This class normalizes them to the
 * stable scalar/list view consumed by existing PHP and shell checks.
 */
final class Policy
{
    public const CONFIG_FILE = 'civikitchen.yaml';

    public const LEGACY_FILE = '.ckconform';

    /**
     * Every normalized key a consumer may read, and its owner. Public YAML
     * property names are enforced by the JSON Schema before normalization.
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
        'deploy_hygiene' => 'ckconform: deploy-only files deliberately shipped, reason mandatory',
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
        'extension_source' => 'entrypoint: key@HTTPS-URL#sha256=digest for a dependency, one per source',
        'extension_version' => 'entrypoint: key@Composer-version-constraint for a pinned dependency',
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
    public const REPEATABLE = ['dist_exclude', 'dist_include', 'lifecycle_log_ignore', 'vendored_paths', 'smarty_skip_templates', 'extension_source', 'extension_version'];

    /** @var list<string> */
    public const PERCENT = ['min_coverage', 'mutation_min_msi', 'mutation_min_covered_msi'];

    /**
     * Environment variable naming an organisation-wide defaults file in the
     * same format. Its keys apply to every repo whose tools see the variable;
     * the repo's own `civikitchen.yaml` overrides per key.
     */
    public const DEFAULTS_ENV = 'CK_DEFAULT_CONFIG';

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
     * The view every reader gets: the repo config over the CK_DEFAULT_CONFIG
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
     * Parse the policy mapping from a civikitchen.yaml document. Unknown keys
     * are returned so PolicyKeyCheck can produce the focused diagnostic.
     *
     * @return array<string, list<string>> key => values, in file order
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > 1024 * 1024) {
            throw new \RuntimeException(self::CONFIG_FILE . ' exceeds the 1 MiB configuration limit');
        }
        self::loadYaml();
        $first = ltrim($raw)[0] ?? '';
        if ($first === '{' || $first === '[') {
            throw new \RuntimeException(self::CONFIG_FILE . ' must use YAML mapping syntax, not JSON syntax');
        }
        try {
            $document = Yaml::parse($raw, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new \RuntimeException('invalid ' . self::CONFIG_FILE . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException(self::CONFIG_FILE . ' root must be a YAML mapping');
        }
        self::validateDocument($document);
        $policy = $document['policy'] ?? [];
        $out = [];
        foreach (['license', 'copyright', 'hook_style', 'npm_license', 'max_unreleased_days', 'renovate_preset'] as $key) {
            if (array_key_exists($key, $policy)) $out[$key] = [(string) $policy[$key]];
        }
        if (isset($policy['coverage']['minimum'])) $out['min_coverage'] = [(string) $policy['coverage']['minimum']];
        if (isset($policy['ignore_checks'])) $out['ignore_checks'] = [implode(',', $policy['ignore_checks']['checks']) . ' -- ' . $policy['ignore_checks']['reason']];
        if (isset($policy['tests'])) $out['tests'] = [$policy['tests']['mode'] . ' -- ' . $policy['tests']['reason']];
        if (isset($policy['vendor'])) $out['vendor'] = [is_array($policy['vendor']) ? $policy['vendor']['mode'] . ' -- ' . $policy['vendor']['reason'] : $policy['vendor']];
        if (isset($policy['known_hooks'])) $out['known_hooks'] = [implode(',', $policy['known_hooks'])];
        if (isset($policy['known_api4_entities'])) $out['known_api4_entities'] = [implode(',', $policy['known_api4_entities']['entities']) . ' -- ' . $policy['known_api4_entities']['reason']];
        if (isset($policy['bundles'])) $out['bundles'] = [$policy['bundles']['mode'] . ' -- ' . $policy['bundles']['reason']];
        if (isset($policy['deploy_hygiene'])) $out['deploy_hygiene'] = [implode(',', $policy['deploy_hygiene']['paths']) . ' -- ' . $policy['deploy_hygiene']['reason']];
        foreach ($policy['vendored_paths'] ?? [] as $item) $out['vendored_paths'][] = $item['path'] . ' -- ' . $item['reason'];
        foreach ($policy['smarty_skip_templates'] ?? [] as $item) $out['smarty_skip_templates'][] = $item['template'] . ' -- ' . $item['reason'];
        if (isset($policy['release'])) $out['release'] = [$policy['release']['mode'] . ' -- ' . $policy['release']['reason']];
        if (isset($policy['mutation']['minimum_msi'])) $out['mutation_min_msi'] = [(string) $policy['mutation']['minimum_msi']];
        if (isset($policy['mutation']['minimum_covered_msi'])) $out['mutation_min_covered_msi'] = [(string) $policy['mutation']['minimum_covered_msi']];
        if (isset($policy['mutation']['paths'])) $out['mutation_paths'] = [implode(',', $policy['mutation']['paths'])];
        foreach ($policy['dist']['exclude'] ?? [] as $path) $out['dist_exclude'][] = $path;
        if (isset($policy['dist']['include'])) {
            foreach ($policy['dist']['include'] as $item) $out['dist_include'][] = $item['path'] . ' -- ' . $item['reason'];
        }
        foreach ($policy['lifecycle']['log_ignore'] ?? [] as $item) $out['lifecycle_log_ignore'][] = $item['pattern'] . ' -- ' . $item['reason'];
        if (isset($policy['template_custom'])) $out['template_custom'] = [implode(',', $policy['template_custom']['paths']) . ' -- ' . $policy['template_custom']['reason']];
        $sourceKeys = [];
        foreach ($policy['extension_sources'] ?? [] as $item) {
            if (isset($sourceKeys[$item['key']])) {
                throw new \RuntimeException(self::CONFIG_FILE . ': policy.extension_sources repeats key ' . $item['key']);
            }
            $sourceKeys[$item['key']] = true;
            try {
                (new VersionParser())->parseConstraints($item['version']);
            } catch (\UnexpectedValueException $e) {
                throw new \RuntimeException(self::CONFIG_FILE . ': policy.extension_sources has invalid Composer version constraint for ' . $item['key'] . ': ' . $item['version'], 0, $e);
            }
            $out['extension_source'][] = $item['key'] . '@' . $item['url'] . '#sha256=' . strtolower($item['sha256']) . ' -- ' . $item['reason'];
            $out['extension_version'][] = $item['key'] . '@' . $item['version'];
        }
        return $out;
    }

    /** @param array<string, mixed> $document */
    private static function validateDocument(array $document): void
    {
        if (!class_exists('CkProfileSchemaValidator')) {
            foreach ([
                dirname(__DIR__, 3) . '/packages/civicrm-profile-schema/validate.php',
                '/usr/local/share/civikitchen/profile-schema/validate.php',
            ] as $validator) {
                if (is_file($validator)) {
                    require_once $validator;
                    break;
                }
            }
        }
        if (!class_exists('CkProfileSchemaValidator')) {
            throw new \RuntimeException('CiviKitchen configuration validator is missing');
        }
        foreach ([
            dirname(__DIR__, 3) . '/packages/civikitchen-scenario-schema/scenario.schema.json',
            '/usr/local/share/civikitchen/scenario-schema/scenario.schema.json',
        ] as $schemaFile) {
            if (is_file($schemaFile)) break;
        }
        if (!isset($schemaFile) || !is_file($schemaFile)) {
            throw new \RuntimeException('CiviKitchen configuration schema is missing');
        }
        $schema = json_decode((string) file_get_contents($schemaFile), true, 512, JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $errors = (new \CkProfileSchemaValidator($schema))->validate($object);
        if ($errors !== []) throw new \RuntimeException(implode("\n", $errors));
    }

    private static function loadYaml(): void
    {
        if (class_exists(Yaml::class)) {
            return;
        }
        foreach ([
            dirname(__DIR__, 3) . '/packages/civikitchen-scenario-schema/vendor/autoload.php',
            '/usr/local/share/civikitchen/scenario-schema/vendor/autoload.php',
        ] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists(Yaml::class)) {
                    return;
                }
            }
        }
        throw new \RuntimeException('CiviKitchen YAML parser dependency is missing');
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
