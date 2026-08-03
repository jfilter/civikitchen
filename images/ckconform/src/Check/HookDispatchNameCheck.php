<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookCatalog;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * A hook implementation only fires under this extension's own hook prefix, and
 * only if the hook still exists.
 *
 * CiviCRM resolves `hook_civicrm_post` to `<prefix>_civicrm_post`, where
 * `<prefix>` is the extension's file name (`<file>` in info.xml, i.e. the
 * basename of its main PHP file). A function copied out of another extension —
 * `otherext_civicrm_post()` — is perfectly valid PHP, loads without a murmur
 * and is never called. Same for a typo'd hook name: `..._civicrm_postCommmit`
 * is dead code that looks like a working feature. A hook core has since removed
 * fails the same way, and just as quietly.
 *
 * Nothing else catches the removed and deprecated cases. A hook implementation
 * is a function *definition* matching a naming convention, not a call to a
 * deprecated symbol, so static analysis of deprecations has nothing to bind to.
 *
 * The listener forms fail just as silently, so they are scanned too: a
 * 'hook_civicrm_*' string literal (addListener, getSubscribedEvents, dispatch)
 * and a scan-classes listener method (`hook_*` / `on_*` / `self_*`, the three
 * prefixes EventScanner::findFunctionListeners matches) both bind by name, and
 * a typo in either registers a listener no event will ever reach.
 *
 * Four verdicts, deliberately asymmetric:
 *  - a wrong prefix FAILS, because the expected prefix is knowable from
 *    info.xml, so the mismatch is statically proven;
 *  - a removed hook FAILS, because core no longer dispatches the name at all;
 *  - a deprecated hook WARNS, because it still fires today;
 *  - an unrecognised suffix WARNS, because third-party extensions publish
 *    their own hooks (`hook_civicrm_acmeConnectors`) that no embedded list can
 *    enumerate.
 */
final class HookDispatchNameCheck implements Check
{
    /**
     * Hooks CiviCRM has removed: suffix => guidance.
     *
     * Hand-verified, and deliberately not generated. A removed hook leaves no
     * trace in a core checkout, so the only machine-readable signal is dev-docs
     * prose — and deriving from it condemned live hooks in every attempt
     * (`post`, `install` and `enable` because core builds those names
     * dynamically; `alterExternUrl` because it is dispatched from
     * CRM/Utils/System.php rather than CRM_Utils_Hook). Each entry below was
     * confirmed absent from CRM/, Civi/, api/ and ext/ in a 6.14 checkout,
     * ignoring CRM/Upgrade/Incremental/ — upgrade steps still name hooks that
     * were removed years ago.
     *
     * @var array<string, string>
     */
    private const REMOVED_HOOKS = [
        'alterMail' => 'removed from core — use hook_civicrm_alterMailParams',
        'crudLink' => 'removed in 5.33',
        'customFieldOptions' => 'removed in 5.78 — use hook_civicrm_fieldOptions',
        'enableDisable' => 'removed in 4.5 — use hook_civicrm_pre / hook_civicrm_post',
        'notePrivacy' => 'removed in 5.67',
        'tabs' => 'removed in 5.31 — use hook_civicrm_tabset',
        'validate' => 'removed in 4.7 — use hook_civicrm_validateForm',
    ];

    /**
     * Deprecations that exist only in the dev docs, never as a core marker.
     *
     * The generator reads core's `@deprecated` tags and its runtime deprecation
     * warnings; these two carry neither. Auditing every dev-docs page mentioning
     * "deprecated" yielded exactly these two real hook deprecations alongside
     * two pages describing a deprecated *parameter* (`links`, `merge`), so the
     * shorter and more honest route is to list them.
     *
     * @var array<string, string>
     */
    private const DOCS_DEPRECATED_HOOKS = [
        'apiWrappers' => 'deprecated — use the civi.api.prepare / civi.api.respond events',
        'contactListQuery' => 'deprecated in favour of hook_civicrm_apiWrappers',
    ];

    public function name(): string
    {
        return 'hook-dispatch-name';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $expected = HookSurface::expectedPrefixes($context);
        if ($expected === []) {
            return;
        }

        // Hooks invented by other (possibly private) extensions can't live in
        // the public KNOWN_HOOKS list — a repo declares them in its own
        // .ckconform policy: known_hooks=acmeConnectors,otherHook
        $policyHooks = array_filter(array_map('trim', explode(',', $context->policyValue('known_hooks') ?? '')));

        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, '_civicrm_')) {
                continue;
            }
            $suppressions = Suppressions::of($contents);

            foreach (HookSurface::globalFunctions($contents) as $function => $line) {
                if (preg_match('/^([A-Za-z0-9_]+)_civicrm_([a-zA-Z]+)$/', $function, $m) !== 1) {
                    continue;
                }
                [, $prefix, $suffix] = $m;

                // `civicrm_civicrm_*` is core's own prefix, and the api magic
                // functions are not hooks at all.
                if ($prefix === 'civicrm' || str_starts_with($function, 'civicrm_api3_')
                    || str_contains($function, '_civicrm_api3_')
                    || $suppressions->suppressed($this->name(), $line)
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

                $this->judgeSuffix($file, $function . '()', $suffix, $policyHooks, $reporter);
            }

            foreach ($this->listenerMethods($contents) as [$method, $suffix, $line]) {
                if ($suppressions->suppressed($this->name(), $line)) {
                    continue;
                }
                $this->judgeSuffix($file, $method . '() (scan-classes listener)', $suffix, $policyHooks, $reporter);
            }

            foreach ($this->hookNameStrings($contents) as [$name, $suffix, $line]) {
                if ($suppressions->suppressed($this->name(), $line)) {
                    continue;
                }
                $this->judgeSuffix($file, "listener/dispatch string '{$name}'", $suffix, $policyHooks, $reporter);
            }
        }
    }

    /**
     * The suffix verdict shared by all three binding forms; the prefix rule is
     * function-only and stays with its loop.
     *
     * @param list<string> $policyHooks
     */
    private function judgeSuffix(string $file, string $subject, string $suffix, array $policyHooks, Reporter $reporter): void
    {
        // A repo that declares a suffix in .ckconform is asserting a live
        // third-party hook under that name; core's history about a same-named
        // hook then says nothing about this code.
        if (in_array($suffix, $policyHooks, true)) {
            return;
        }

        if (isset(self::REMOVED_HOOKS[$suffix])) {
            $reporter->fail(sprintf(
                '%s: %s will never fire — hook_civicrm_%s was %s',
                $file,
                $subject,
                $suffix,
                self::REMOVED_HOOKS[$suffix],
            ));
            return;
        }

        $deprecation = HookCatalog::DEPRECATED[$suffix] ?? self::DOCS_DEPRECATED_HOOKS[$suffix] ?? null;
        if ($deprecation !== null) {
            $reporter->warn(sprintf(
                '%s: %s — hook_civicrm_%s is %s',
                $file,
                $subject,
                $suffix,
                $deprecation,
            ));
            return;
        }

        if (!in_array($suffix, HookCatalog::LIVE, true)) {
            $reporter->warn(sprintf(
                '%s: %s — unknown hook suffix \'%s\'; a typo never fires, a third-party hook is fine (declare it via known_hooks= in .ckconform)',
                $file,
                $subject,
                $suffix,
            ));
        }
    }

    /**
     * Methods (any depth) whose name EventScanner would bind to a hook:
     * `hook_civicrm_x`, `on_hook_civicrm_x`, `self_hook_civicrm_x`. Dotted
     * events (`on_civi_foo_bar` → civi.foo.bar) are a different namespace the
     * catalog cannot judge. A stray *global* function under these names is dead
     * code too, so no depth filter.
     *
     * @return list<array{string, string, int}> [method name, hook suffix, line]
     */
    private function listenerMethods(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $methods = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== \T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (is_array($next) && $next[0] === \T_STRING
                    && preg_match('/^(?:on_|self_)?hook_civicrm_([a-zA-Z]+)$/', $next[1], $m) === 1
                ) {
                    $methods[] = [$next[1], $m[1], $next[2]];
                }
                break;
            }
        }

        return $methods;
    }

    /**
     * String literals naming a hook event: addListener/getSubscribedEvents/
     * dispatch all bind by exactly such a string. A leading '&' (by-reference
     * marker) and an '::Entity' scope (the self_ form's target) wrap the same
     * name and are stripped before judging. Only plain single-part literals are
     * seen — interpolated strings build names the scan cannot resolve.
     *
     * @return list<array{string, string, int}> [literal (unquoted), hook suffix, line]
     */
    private function hookNameStrings(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $names = [];

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literal = substr($token[1], 1, -1);
            if (preg_match('/^&?hook_civicrm_([a-zA-Z]+)(?:::[A-Za-z_]+)?$/', $literal, $m) === 1) {
                $names[] = [$literal, $m[1], $token[2]];
            }
        }

        return $names;
    }
}
