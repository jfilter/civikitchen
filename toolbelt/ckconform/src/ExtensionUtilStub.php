<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Stand-in for CRM_*_ExtensionUtil so `return [...]` config files (mgd.php,
 * settings, .aff.php) can be evaluated outside a CiviCRM boot.
 */
final class ExtensionUtilStub
{
    /** @var array{0: string, 1: string} SHORT_NAME and LONG_NAME of the extension being checked */
    private static array $names = ['', ''];

    /**
     * Autoload stub for any CRM_*_ExtensionUtil so `use ... as E; E::ts()`
     * works outside a CiviCRM boot. ts() returns the literal; every other
     * static returns the first argument or ''. The civix constants SHORT_NAME,
     * LONG_NAME and CLASS_PREFIX come from $context's info.xml. Bare ts()
     * calls appear in .aff.php metadata too, so a global ts() is defined as
     * well, and CRM_Core_Component::isEnabled() (core's guard in mgd files)
     * answers true so a guarded file still yields its records.
     */
    public static function register(?Context $context = null): void
    {
        if ($context !== null) {
            self::$names = [$context->shortName() ?? '', $context->extensionKey() ?? ''];
        }
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (!function_exists('ts')) {
            eval('function ts($text, $params = []) { return $text; }');
        }

        spl_autoload_register(static function (string $class): void {
            if ($class === 'CRM_Core_Component') {
                eval('class CRM_Core_Component { public static function isEnabled($component) { return true; } }');

                return;
            }
            if (preg_match('/^CRM_\w+_ExtensionUtil$/', $class) !== 1) {
                return;
            }
            eval(sprintf(
                'class %s {
                    const SHORT_NAME = %s;
                    const LONG_NAME = %s;
                    const CLASS_PREFIX = %s;
                    public static function ts($text, $params = []) { return $text; }
                    public static function __callStatic($name, $args) { return $args[0] ?? \'\'; }
                }',
                $class,
                var_export(self::$names[0], true),
                var_export(self::$names[1], true),
                var_export(substr($class, 0, -strlen('_ExtensionUtil')), true),
            ));
        });
    }
}
