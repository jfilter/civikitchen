<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

/**
 * Drift gate for src/SchemaCatalog.php: stale, the SQL rule rejects queries
 * that are fine against a newer core.
 */
final class SchemaCatalogDriftTest extends CatalogDriftTestCase
{
    protected function generator(): string
    {
        return dirname(__DIR__) . '/tools/gen-schema-catalog.php';
    }

    protected function committed(): string
    {
        return dirname(__DIR__) . '/src/SchemaCatalog.php';
    }
}
