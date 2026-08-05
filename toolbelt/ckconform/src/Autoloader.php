<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * PSR-4 autoloading without composer: the tool ships inside an image and must
 * not carry a vendor/ directory that someone has to keep current.
 */
final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = __NAMESPACE__ . '\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/' . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
