<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The committed table catalog must match what the generator produces.
 *
 * Without this, src/SchemaCatalog.php silently rots: a core release that
 * adds a table makes the SQL rule reject queries that are fine, which is
 * the one failure mode a checker like this must not have. Skipped when no
 * core checkout is around — CI provides one pinned to CORE_VERSION.
 */
final class SchemaCatalogDriftTest extends TestCase
{
    public function testTheCommittedCatalogMatchesTheGenerator(): void
    {
        $coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_file($coreDir . '/Civi.php')) {
            self::markTestSkipped("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
        }

        $generator = dirname(__DIR__) . '/tools/gen-schema-catalog.php';
        $committed = dirname(__DIR__) . '/src/SchemaCatalog.php';
        $fresh = tempnam(sys_get_temp_dir(), 'schemacatalog');

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
                'src/SchemaCatalog.php is stale — regenerate: php tools/gen-schema-catalog.php ' . $coreDir,
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
