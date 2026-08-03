<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The committed namespace catalog must match what the generator produces.
 *
 * Without this, src/CoreNamespaceCatalog.php silently rots: a core release
 * that adds a component makes the boundary rule flag core's own classes as
 * foreign extensions. Skipped when no core checkout is around — CI provides
 * one pinned to the catalog's CORE_VERSION.
 */
final class CoreNamespaceCatalogDriftTest extends TestCase
{
    public function testTheCommittedCatalogMatchesTheGenerator(): void
    {
        $coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_file($coreDir . '/Civi.php')) {
            self::markTestSkipped("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
        }

        $generator = dirname(__DIR__) . '/tools/gen-core-namespace-catalog.php';
        $committed = dirname(__DIR__) . '/src/CoreNamespaceCatalog.php';
        $fresh = tempnam(sys_get_temp_dir(), 'nscatalog');

        try {
            exec(sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($generator),
                escapeshellarg($coreDir),
                escapeshellarg($fresh),
            ), $output, $status);
            self::assertSame(0, $status, 'generator failed: ' . implode("\n", $output));

            // The header records the core version, which legitimately differs
            // between the checkout that generated the file and this one.
            self::assertSame(
                $this->withoutHeader((string) file_get_contents($fresh)),
                $this->withoutHeader((string) file_get_contents($committed)),
                'src/CoreNamespaceCatalog.php is stale — regenerate: php tools/gen-core-namespace-catalog.php ' . $coreDir,
            );
        } finally {
            @unlink($fresh);
        }
    }

    private function withoutHeader(string $source): string
    {
        return (string) preg_replace('#^.*?\nfinal class#s', 'final class', $source);
    }
}
