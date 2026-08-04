<?php

declare(strict_types=1);

/**
 * The rule tests need phpstan's own testing harness, the catalog drift tests
 * do not. `composer install` in this directory provides both; without it the
 * package still autoloads from src/ so nothing has to be installed to run
 * the drift gates.
 */
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'CiviKitchen\\PHPStan\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
