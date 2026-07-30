<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A hook implementation only fires under this extension's own hook prefix.
 *
 * CiviCRM resolves `hook_civicrm_post` to `<prefix>_civicrm_post`, where
 * `<prefix>` is the extension's file name (`<file>` in info.xml, i.e. the
 * basename of its main PHP file). A function copied out of another extension —
 * `otherext_civicrm_post()` — is perfectly valid PHP, loads without a murmur
 * and is never called. Same for a typo'd hook name: `..._civicrm_postCommmit`
 * is dead code that looks like a working feature.
 *
 * Two rules, deliberately asymmetric:
 *  - wrong prefix is a FAIL, because the expected prefix is knowable from
 *    info.xml, so the mismatch is statically proven;
 *  - an unrecognised hook suffix is only a warn, because third-party
 *    extensions publish their own hooks (`hook_civicrm_acmeConnectors`)
 *    that no embedded list can enumerate.
 */
final class HookDispatchNameCheck implements Check
{
    /**
     * Core hook suffixes, generously scoped. Absence means "unknown", never
     * "wrong" — see the class docblock for why this can only warn.
     *
     * @var list<string>
     */
    private const KNOWN_HOOKS = [
        'aclGroup', 'aclWhereClause', 'alterAdminPanel', 'alterAngular', 'alterAPIPermissions',
        'alterBundle', 'alterContent', 'alterCustomFieldDisplayValue', 'alterEntityRefParams',
        'alterExternUrl', 'alterLocationMergeData', 'alterLogTables', 'alterMailer',
        'alterMailParams', 'alterMailStore', 'alterMenu', 'alterPaymentProcessorParams',
        'alterReportVar', 'alterSettingsFolders', 'alterSettingsMetaData', 'alterTemplateFile',
        'angularModules', 'apiWrappers', 'autocompleteOptions', 'batchItems', 'batchQuery',
        'buildAmount', 'buildAsset', 'buildForm', 'caseTypes', 'check', 'checkAccess',
        'civicrmProfiles', 'config', 'contactListQuery', 'contactSummaryBlocks', 'container',
        'contributionRecur', 'copy', 'coreResourceList', 'custom', 'dashboard', 'disable',
        'dupeQuery', 'emailProcessor', 'enable', 'entityTypes', 'exportComponents',
        'fieldOptions', 'findDuplicates', 'install', 'links', 'mailingGroups',
        'mailSetupActions', 'managed', 'membershipTypeValues', 'merge', 'navigationMenu',
        'notePrivacy', 'pageRun', 'permission', 'post', 'postCommit', 'postInstall', 'postProcess',
        'pre', 'preProcess', 'queryObjects', 'searchColumns', 'searchKitTasks', 'searchTasks',
        'selectWhereClause', 'setDefaults', 'summaryActions', 'tabset', 'themes', 'tokens',
        'tokenValues', 'triggerInfo', 'uninstall', 'unsubscribeGroups', 'upgrade',
        'validateForm', 'xmlMenu',
    ];

    public function name(): string
    {
        return 'hook-dispatch-name';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $expected = $this->expectedPrefixes($context);
        if ($expected === []) {
            return;
        }

        // Hooks invented by other (possibly private) extensions can't live in
        // the public KNOWN_HOOKS list — a repo declares them in its own
        // .ckconform policy: known_hooks=acmeConnectors,otherHook
        $policyHooks = array_filter(array_map('trim', explode(',', $context->policyValue('known_hooks') ?? '')));

        foreach ($this->candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, '_civicrm_')) {
                continue;
            }

            foreach ($this->globalFunctions($contents) as $function) {
                if (preg_match('/^([A-Za-z0-9_]+)_civicrm_([a-zA-Z]+)$/', $function, $m) !== 1) {
                    continue;
                }
                [, $prefix, $suffix] = $m;

                // `civicrm_civicrm_*` is core's own prefix, and the api magic
                // functions are not hooks at all.
                if ($prefix === 'civicrm' || str_starts_with($function, 'civicrm_api3_')
                    || str_contains($function, '_civicrm_api3_')
                ) {
                    continue;
                }

                if (!in_array($prefix, $expected, true)) {
                    $reporter->fail(sprintf(
                        '%s: %s() will never fire — the hook prefix of this extension is \'%s\', so the function must be named %s_civicrm_%s()',
                        $file,
                        $function,
                        $expected[0],
                        $expected[0],
                        $suffix,
                    ));
                    continue;
                }

                if (!in_array($suffix, self::KNOWN_HOOKS, true) && !in_array($suffix, $policyHooks, true)) {
                    $reporter->warn(sprintf(
                        '%s: %s() — unknown hook suffix \'%s\'; a typo never fires, a third-party hook is fine (declare it via known_hooks= in .ckconform)',
                        $file,
                        $function,
                        $suffix,
                    ));
                }
            }
        }
    }

    /**
     * Every prefix under which a hook may legitimately be declared: the `<file>`
     * from info.xml, the last segment of the `<key>`, and the basename of a
     * root-level `<shortname>.php` main file. All three normally coincide; when
     * they do not, any of them is plausible enough that failing would be noise.
     * The first entry is the canonical one, and the one the message names.
     *
     * @return list<string>
     */
    private function expectedPrefixes(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }

        $prefixes = [];
        $file = trim((string) ($info->file ?? ''));
        if ($file !== '') {
            $prefixes[] = $file;
        }
        $key = trim((string) ($info['key'] ?? ''));
        if ($key !== '') {
            $segments = explode('.', $key);
            $prefixes[] = (string) end($segments);
        }
        // The main file on disk, recognised by its civix twin: an extension whose
        // root holds both `foo.php` and `foo.civix.php` dispatches under `foo`,
        // whatever info.xml claims. Cheap, and it saves a false FAIL on a repo
        // whose info.xml `<file>` has drifted from the file that is loaded.
        foreach ($context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('') as $candidate) {
            if (preg_match('#^([A-Za-z0-9_]+)\.civix\.php$#', $candidate, $m) === 1
                && $context->exists($m[1] . '.php')
            ) {
                $prefixes[] = $m[1];
            }
        }

        return array_values(array_unique(array_filter(
            $prefixes,
            static fn (string $p): bool => preg_match('/^[A-Za-z0-9_]+$/', $p) === 1,
        )));
    }

    /**
     * Tracked *.php outside tests/ and vendor/. Generated civix glue declares
     * hooks whose prefix civix itself owns, and vendored code is nobody's to
     * rename here.
     *
     * @return list<string>
     */
    private function candidates(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php'])
            : $context->findFiles('', ['.php']);

        return array_values(array_filter($files, static function (string $file): bool {
            return !str_starts_with($file, 'tests/')
                && !str_starts_with($file, 'vendor/')
                && !str_contains($file, '/vendor/')
                && !str_ends_with($file, '.civix.php');
        }));
    }

    /**
     * Top-level function names, by token scan.
     *
     * Brace depth is the whole trick: a `function` seen at depth 0 is global,
     * anything deeper is a method or a nested closure. Interpolation tokens
     * (`T_CURLY_OPEN`, `${`) open a brace that `}` closes, so they have to be
     * counted or a string like "{$a}" would unbalance the rest of the file.
     * A `function` followed by `(` is an anonymous function and has no name.
     *
     * @return list<string>
     */
    private function globalFunctions(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $depth = 0;
        $names = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }

            if ($token[0] === \T_CURLY_OPEN || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if ($token[0] !== \T_FUNCTION || $depth !== 0) {
                continue;
            }

            // Skip whitespace/comments and a by-reference '&' to reach the name.
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (is_array($next) && $next[0] === \T_STRING && !str_starts_with($next[1], '_')) {
                    $names[] = $next[1];
                }
                break;
            }
        }

        return $names;
    }
}
