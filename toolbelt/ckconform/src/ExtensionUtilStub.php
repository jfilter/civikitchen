<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Stand-in for CRM_*_ExtensionUtil so `return [...]` config files (mgd.php,
 * settings, .aff.php) can be evaluated outside a CiviCRM boot.
 */
final class ExtensionUtilStub
{
    /**
     * Autoload stub for any CRM_*_ExtensionUtil so `use ... as E; E::ts()`
     * works outside a CiviCRM boot. ts() returns the literal; every other
     * static returns the first argument or ''. Bare ts() calls appear in
     * .aff.php metadata too, so a global ts() is defined as well.
     */
    public static function register(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (!function_exists('ts')) {
            eval('function ts($text, $params = []) { return $text; }');
        }

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
