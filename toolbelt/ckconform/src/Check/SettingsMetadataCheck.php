<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A setting placed on a settings page (settings_pages) without an html_type
 * or quick_form_type takes the whole admin page down: the generic form
 * (CRM_Admin_Form_Generic) passes the empty element type to QuickForm, which
 * throws "unregistered element" while BUILDING the page — a fatal on every
 * visit, invisible until someone opens the page. Found live in production
 * (an Array setting added for a feeder).
 *
 * The files are `return [...]` PHP with at most an ExtensionUtil `E::ts()`
 * dependency, so they can be evaluated outside CiviCRM with a stubbed
 * ExtensionUtil. A file that throws anyway is reported as a warning, not
 * silently skipped.
 */
final class SettingsMetadataCheck implements Check
{
    public function name(): string
    {
        return 'settings-metadata';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle) — an uncommitted local settings
        // file must not sway the verdict. Outside git, fall back to the tree.
        $files = $context->isGitRepo()
            ? $context->trackedUnder('settings', ['setting.php'])
            : $context->findFiles('settings', ['setting.php']);
        if ($files === []) {
            return;
        }

        self::registerExtensionUtilStub();

        foreach ($files as $relative) {
            try {
                $settings = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate settings file outside CiviCRM ({$e->getMessage()}) — settings metadata unchecked");
                continue;
            }

            if (!is_array($settings)) {
                $reporter->fail("$relative: does not return an array of setting definitions");
                continue;
            }

            foreach ($settings as $key => $meta) {
                if (!is_array($meta)) {
                    $reporter->fail("$relative: setting '$key' is not an array");
                    continue;
                }
                if (($meta['name'] ?? null) !== $key) {
                    $reporter->fail("$relative: setting '$key' has a diverging 'name' attribute ('" . ($meta['name'] ?? '') . "')");
                }
                if (!empty($meta['settings_pages'])
                    && empty($meta['html_type'])
                    && empty($meta['quick_form_type'])
                ) {
                    $reporter->fail("$relative: setting '$key' is on a settings page (settings_pages) but has no html_type/quick_form_type — the generic settings form fatals with QuickForm \"unregistered element\"");
                }
            }
        }
    }

    /**
     * Autoload stub for any CRM_*_ExtensionUtil so `use ... as E; E::ts()`
     * works outside a CiviCRM boot. ts() returns the literal; every other
     * static returns the first argument or ''.
     */
    private static function registerExtensionUtilStub(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function (string $class): void {
            if (preg_match('/^CRM_\w+_ExtensionUtil$/', $class) !== 1) {
                return;
            }
            eval(sprintf(
                'class %s {
                    public static function ts($text, $params = []) { return $text; }
                    public static function __callStatic($name, $args) { return $args[0] ?? \'\'; }
                }',
                $class,
            ));
        });
    }
}
