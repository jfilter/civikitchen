<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

/**
 * Drift gate for src/CoreNamespaceCatalog.php: stale, the boundary rule flags
 * core's own classes as foreign extensions.
 */
final class CoreNamespaceCatalogDriftTest extends CatalogDriftTestCase
{
    protected function generator(): string
    {
        return dirname(__DIR__) . '/tools/gen-core-namespace-catalog.php';
    }

    protected function committed(): string
    {
        return dirname(__DIR__) . '/src/CoreNamespaceCatalog.php';
    }
}
