<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use PHPUnit\Framework\TestCase;

/**
 * Builds a throwaway extension directory per test and runs a single check
 * against it.
 *
 * Why fixtures and not just the golden output: half of the bash checks printed
 * nothing when they passed, so a port could silently drop one and the golden
 * comparison would still be green. A check without a fixture that makes it FAIL
 * has never been shown to fire at all.
 */
abstract class CheckTestCase extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            $this->deleteTree($this->dir);
        }
        $this->dir = null;
        parent::tearDown();
    }

    /**
     * @param array<string, string> $files Repo-relative path => contents.
     *                                     An 'info.xml' is supplied unless given.
     */
    protected function repo(array $files, bool $git = false): Context
    {
        $this->dir = sys_get_temp_dir() . '/ckconform-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $files['info.xml'] ??= $this->infoXml();
        foreach ($files as $path => $contents) {
            $full = $this->dir . '/' . $path;
            $parent = dirname($full);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($full, $contents);
        }

        if ($git) {
            $this->git('init -q');
            $this->git('add -A');
        }

        return new Context($this->dir, $this->coreDir());
    }

    /**
     * A minimal but valid info.xml. Named arguments keep the interesting bit of
     * each test visible instead of buried in boilerplate XML.
     */
    protected function infoXml(
        string $key = 'fixture',
        string $license = 'Proprietary',
        string $compatibility = '6.10',
        string $extra = '',
    ): string {
        return <<<XML
            <?xml version="1.0"?>
            <extension key="{$key}" type="module">
              <file>{$key}</file>
              <name>Fixture</name>
              <license>{$license}</license>
              <compatibility>
                <ver>{$compatibility}</ver>
              </compatibility>
            {$extra}
            </extension>
            XML;
    }

    protected function run_(Check $check, Context $context): Reporter
    {
        $reporter = new Reporter();
        $check->run($context, $reporter);

        return $reporter;
    }

    protected function assertFails(Reporter $reporter, string $needle = ''): void
    {
        $failures = $reporter->messages('FAIL');
        self::assertNotSame([], $failures, 'expected a FAIL, got: ' . $reporter->render());
        if ($needle !== '') {
            self::assertStringContainsString($needle, implode("\n", $failures));
        }
    }

    protected function assertPasses(Reporter $reporter): void
    {
        self::assertSame(0, $reporter->failures(), 'expected no FAIL, got: ' . $reporter->render());
    }

    protected function assertWarns(Reporter $reporter, string $needle = ''): void
    {
        $warnings = $reporter->messages('warn');
        self::assertNotSame([], $warnings, 'expected a warn, got: ' . $reporter->render());
        if ($needle !== '') {
            self::assertStringContainsString($needle, implode("\n", $warnings));
        }
    }

    protected function assertSilent(Reporter $reporter): void
    {
        self::assertSame([], $reporter->results(), 'expected no output, got: ' . $reporter->render());
    }

    protected function coreDir(): ?string
    {
        return null;
    }

    protected function git(string $args): void
    {
        exec('git -C ' . escapeshellarg((string) $this->dir) . ' ' . $args . ' 2>/dev/null');
    }

    private function deleteTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
