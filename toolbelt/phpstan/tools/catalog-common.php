<?php

declare(strict_types=1);

/**
 * Helpers shared by the gen-*-catalog.php generators in this directory.
 */

/** Classloader roots: core itself plus every ext dir carrying an info.xml. */
function classloaderRoots(string $coreDir): array
{
    $roots = [$coreDir];
    if (!is_dir($coreDir . '/ext')) {
        return $roots;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($coreDir . '/ext', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $it->setMaxDepth(2);
    foreach ($it as $entry) {
        if ($entry->isFile() && $entry->getFilename() === 'info.xml') {
            $roots[] = $entry->getPath();
        }
    }

    return $roots;
}

/** The release declared in xml/version.xml, or 'unknown'. */
function coreVersion(string $coreDir): string
{
    $versionXml = $coreDir . '/xml/version.xml';
    if (is_file($versionXml) && preg_match('#<version_no>([^<]+)</version_no>#', (string) file_get_contents($versionXml), $m)) {
        return trim($m[1]);
    }

    return 'unknown';
}

/** Names as var_export'ed catalog lines, $perLine per line. */
function renderCatalogList(array $names, int $perLine): string
{
    $out = '';
    foreach (array_chunk($names, $perLine) as $chunk) {
        $out .= '        ' . implode(', ', array_map(static fn ($n) => var_export($n, true), $chunk)) . ",\n";
    }

    return $out;
}
