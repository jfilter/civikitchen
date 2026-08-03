<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The committed hook catalog must match what the generator produces.
 *
 * Without this, `src/HookCatalog.php` silently rots: core adds hooks every
 * release, and a stale catalog reports them as typos. Skipped when no core
 * checkout is around, the same way the APIv4 checks degrade — ckconform has to
 * stay runnable in a CI job that has no CiviCRM.
 */
final class HookCatalogDriftTest extends TestCase
{
    public function testTheCommittedCatalogMatchesTheGenerator(): void
    {
        $coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_file($coreDir . '/CRM/Utils/Hook.php')) {
            self::markTestSkipped("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
        }

        $generator = dirname(__DIR__) . '/tools/gen-hook-catalog.php';
        $committed = dirname(__DIR__) . '/src/HookCatalog.php';
        $fresh = tempnam(sys_get_temp_dir(), 'hookcatalog');

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
                'src/HookCatalog.php is stale — regenerate: php tools/gen-hook-catalog.php ' . $coreDir,
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
