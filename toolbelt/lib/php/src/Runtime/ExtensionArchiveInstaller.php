<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ExtensionArchiveInstaller
{
    public function __construct(private readonly ExtensionInspector $inspector)
    {
    }

    public function install(string $archive, string $expectedKey, string $target, string $constraint): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $expectedKey) !== 1) {
            throw new RuntimeException('unsafe extension key in digest-pinned source');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('invalid extension ZIP');
        }
        try {
            $top = $this->validateEntries($zip);
            if (file_exists($target)) {
                throw new RuntimeException('extension target exists');
            }
            $temporary = dirname($target) . '/.civikitchen-extract-' . bin2hex(random_bytes(8));
            if (!mkdir($temporary, 0700)) {
                throw new RuntimeException('could not create extension extraction directory');
            }
            try {
                if (!$zip->extractTo($temporary)) {
                    throw new RuntimeException('could not extract extension ZIP');
                }
                $source = $temporary . '/' . $top;
                $xml = $this->inspector->load($source . '/info.xml', $expectedKey);
                $this->inspector->assertVersion($xml, $constraint);
                if (!rename($source, $target)) {
                    throw new RuntimeException('could not install extension ZIP');
                }
            } finally {
                $this->removeTree($temporary);
            }
        } finally {
            $zip->close();
        }
    }

    private function validateEntries(ZipArchive $zip): string
    {
        $top = null;
        $total = 0;
        if ($zip->numFiles > 10000) {
            throw new RuntimeException('extension ZIP exceeds extraction limits');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || $name === '' || str_contains($name, '\\') || str_starts_with($name, '/')) {
                throw new RuntimeException('unsafe extension ZIP path');
            }
            $parts = explode('/', rtrim($name, '/'));
            if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
                throw new RuntimeException('unsafe extension ZIP path');
            }
            $top ??= $parts[0];
            if ($top !== $parts[0]) {
                throw new RuntimeException('extension ZIP needs one root directory');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 268435456) {
                throw new RuntimeException('extension ZIP exceeds extraction limits');
            }
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                $kind = ($attributes >> 16) & 0170000;
                if ($kind === 0120000) {
                    throw new RuntimeException('extension ZIP contains a symlink');
                }
            }
        }
        if ($top === null) {
            throw new RuntimeException('extension ZIP is empty');
        }
        return $top;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
