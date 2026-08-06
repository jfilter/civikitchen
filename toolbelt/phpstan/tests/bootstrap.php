<?php

declare(strict_types=1);

/**
 * The rule tests need phpstan's own testing harness, the catalog drift tests
 * do not. `composer install` in this directory provides both; without it the
 * package still autoloads from src/ so nothing has to be installed to run
 * the drift gates.
 */
// The composer autoload knows src/ and the fixtures, but not the test
// support classes (shared base cases) in this directory.
spl_autoload_register(static function (string $class): void {
    $prefix = 'CiviKitchen\\PHPStan\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

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
