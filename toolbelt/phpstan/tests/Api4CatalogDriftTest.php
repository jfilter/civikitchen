<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

/**
 * Drift gate for src/Api4Catalog.php: stale, the contract rule rejects calls
 * that are fine against a newer core.
 */
final class Api4CatalogDriftTest extends CatalogDriftTestCase
{
    protected function generator(): string
    {
        return dirname(__DIR__) . '/tools/gen-api4-catalog.php';
    }

    protected function committed(): string
    {
        return dirname(__DIR__) . '/src/Api4Catalog.php';
    }
}
