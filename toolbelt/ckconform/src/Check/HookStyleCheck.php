<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Enforces a repo-declared hook implementation style.
 *
 * Opt-in via `civikitchen.yaml`: `hook_style=listener` states that business hook
 * logic lives in scan classes (AutoSubscriber / HookInterface, registered by
 * the scan-classes mixin), and that the classic `<prefix>_civicrm_<suffix>()`
 * function form is reserved for the hooks that technically allow nothing else:
 * pre-boot hooks (dispatched before the container exists), the lifecycle hooks
 * (dispatched straight at the module, never through Symfony) and hooks whose
 * return value is the contract.
 *
 * Without the policy key the check is silent — which style a repo wants is the
 * repo's business, not this tool's. An unknown value fails loudly rather than
 * silently enforcing nothing.
 *
 * Warnings, not failures: a classic business hook still works; the style line
 * exists so new code lands in classes, not to break builds over old code.
 */
final class HookStyleCheck implements Check
{
    /**
     * Suffixes the classic function form is reserved for under
     * hook_style=listener.
     *
     * @var list<string>
     */
    private const CLASSIC_ONLY = [
        // Pre-boot: dispatched through the module subsystem before the
        // container exists, so no container-registered listener can hear them.
        'entityTypes',
        'container',
        // Lifecycle: dispatched straight at the extension via
        // CRM_Extension_Manager_Module::callHook, never through Symfony — and
        // at install time the extension's classes are not scanned yet anyway.
        'install',
        'postInstall',
        'uninstall',
        'enable',
        'disable',
        'upgrade',
        // The civix stub every main file carries.
        'config',
        // Hooks whose return value is the contract; a listener can only
        // approximate that through event mutation.
        'permission',
        'check',
        'validateForm',
        'dashboard',
        'summary',
        'caseSummary',
        'relativeDate',
    ];

    public function name(): string
    {
        return 'hook-style';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $style = $context->policyValue('hook_style');
        if ($style === null) {
            return;
        }
        if ($style !== 'listener') {
            $reporter->fail(sprintf(
                "civikitchen.yaml: unknown hook_style '%s' — the only supported value is 'listener'",
                $style,
            ));
            return;
        }

        $expected = HookSurface::expectedPrefixes($context);
        if ($expected === []) {
            return;
        }

        $listenerClasses = false;
        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            $listenerClasses = $listenerClasses || $this->usesListenerClasses($contents);

            if (stripos($contents, '_civicrm_') === false) {
                continue;
            }
            $suppressions = Suppressions::of($contents);
            foreach (HookSurface::globalFunctions($contents) as $function => $line) {
                if (preg_match('/^([A-Za-z0-9_]+)_civicrm_([a-zA-Z]+)$/i', $function, $m) !== 1) {
                    continue;
                }
                [, $prefix, $suffix] = $m;
                // Foreign prefixes are HookDispatchNameCheck's finding, not a
                // style question; the classic-only remainder is exactly what
                // the style permits as functions.
                if (!HookSurface::hasPrefix($prefix, $expected)
                    || in_array(strtolower($suffix), array_map('strtolower', self::CLASSIC_ONLY), true)
                    || $suppressions->suppressed($this->name(), $line)
                ) {
                    continue;
                }
                $reporter->warn(sprintf(
                    '%s: %s() — hook_style=listener: business hooks live in scan classes (AutoSubscriber/HookInterface); the classic function form is reserved for pre-boot, lifecycle and return-value hooks',
                    $file,
                    $function,
                ));
            }
        }

        // A listener class without the scan-classes mixin registers nothing —
        // the style's own failure mode. A warn, because a repo could still
        // register the class by hand through hook_civicrm_container.
        if ($listenerClasses && !$this->declaresScanClasses($context)) {
            $reporter->warn(
                'listener classes exist (AutoSubscriber/HookInterface/AutoService) but info.xml declares no scan-classes mixin — nothing registers them'
            );
        }
    }

    /**
     * Whether the file references the scan-class bases, judged on real code
     * tokens so comments and strings cannot trip it; bare, imported or
     * fully qualified, in any case, as PHP resolves class names.
     */
    private function usesListenerClasses(string $contents): bool
    {
        if (preg_match('/AutoSubscriber|HookInterface|AutoService/i', $contents) !== 1) {
            return false;
        }
        // Only a class's parents, interfaces and imports; a method hookInterface() is none of them.
        $tokens = PhpSource::codeTokens($contents);
        $inList = false;
        foreach ($tokens as $i => $token) {
            if ($token->is([T_EXTENDS, T_IMPLEMENTS]) || ($token->is(T_USE) && ($tokens[$i + 1] ?? null)?->text !== '(')) {
                $inList = true;
            } elseif ($token->is([';', '{']) && !($tokens[$i - 1] ?? null)?->is(T_NS_SEPARATOR)) {
                $inList = false;
            } elseif ($inList && in_array(strtolower(PhpSource::shortName($token) ?? ''), ['autosubscriber', 'hookinterface', 'autoservice'], true)) {
                return true;
            }
        }

        return false;
    }

    private function declaresScanClasses(Context $context): bool
    {
        $info = $context->infoXml();
        if ($info === null) {
            return false;
        }
        foreach ($info->xpath('//mixins/mixin') ?: [] as $mixin) {
            if (str_starts_with(trim((string) $mixin), 'scan-classes@')) {
                return true;
            }
        }

        return false;
    }
}
