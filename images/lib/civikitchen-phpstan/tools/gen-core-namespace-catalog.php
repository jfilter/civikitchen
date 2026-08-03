<?php

declare(strict_types=1);

/**
 * Regenerate src/CoreNamespaceCatalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-core-namespace-catalog.php <core-dir> [out-file]
 *
 * Collects the CRM_* components and Civi\* top-level namespaces that core
 * OWNS — the boundary rule treats every other CRM_/Civi\ symbol as another
 * extension's internals. Scanned by directory, not by parsing classes: the
 * classloader maps CRM/<X>/ to CRM_<X>_* and Civi/<X>/ to Civi\<X>\*, so the
 * directory names ARE the namespace surface.
 *
 * Core-shipped extensions (ext/, including nested ones like afform/core)
 * count as core: SearchKit, Afform, Authx and friends are core-maintained
 * and depending on them is house style, not a boundary violation. Only dirs
 * whose parent holds an info.xml are classloader roots — that is what keeps
 * templates/CRM/ (Smarty trees, not classes) out of the catalog.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-core-namespace-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

if (!is_file($coreDir . '/Civi.php') || !is_dir($coreDir . '/CRM')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir has no Civi.php/CRM\n");
    exit(66);
}

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

/**
 * Top-level names under a classloader dir: subdirectories plus bare classes
 * (Civi/Test.php declares both the class Civi\Test and its namespace).
 */
function topLevelNames(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $names = [];
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir()) {
            $names[] = $entry->getFilename();
        } elseif ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
            $names[] = substr($entry->getFilename(), 0, -4);
        }
    }

    return $names;
}

/**
 * Namespaces an ext maps via info.xml psr0/psr4 instead of CRM_/Civi\ dirs —
 * flexmailer's Civi\FlexMailer => src/ is invisible to the directory scan.
 */
function classloaderPrefixes(string $root, array &$crm, array &$civi): void
{
    $infoXml = $root . '/info.xml';
    if (!is_file($infoXml)) {
        return;
    }
    if (!preg_match_all('/<psr[04]\s[^>]*prefix="([^"]+)"/', (string) file_get_contents($infoXml), $m)) {
        return;
    }
    foreach ($m[1] as $prefix) {
        if (preg_match('/^CRM_([^_\\\\]+)_?$/', $prefix, $p)) {
            $crm[] = $p[1];
        } elseif (preg_match('/^Civi\\\\([^\\\\]+)\\\\?$/', $prefix, $p)) {
            $civi[] = $p[1];
        }
        // Generic CRM_/Civi\ prefixes carry no name — the dir scan covers them.
    }
}

$crm = [];
$civi = [];
foreach (classloaderRoots($coreDir) as $root) {
    $crm = array_merge($crm, topLevelNames($root . '/CRM'));
    $civi = array_merge($civi, topLevelNames($root . '/Civi'));
    classloaderPrefixes($root, $crm, $civi);
}

$crm = array_values(array_unique($crm));
$civi = array_values(array_unique($civi));
sort($crm);
sort($civi);

$version = 'unknown';
$versionXml = $coreDir . '/xml/version.xml';
if (is_file($versionXml) && preg_match('#<version_no>([^<]+)</version_no>#', (string) file_get_contents($versionXml), $m)) {
    $version = trim($m[1]);
}

$renderList = static function (array $names): string {
    $out = '';
    foreach (array_chunk($names, 5) as $chunk) {
        $out .= '        ' . implode(', ', array_map(static fn ($n) => var_export($n, true), $chunk)) . ",\n";
    }

    return $out;
};

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * CiviCRM core's CRM_/Civi\\ namespace surface — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-core-namespace-catalog.php <core-dir>
 *
 * Generated from CiviCRM {$version}. The boundary rule in ArchitectureTest
 * allows these prefixes (plus the extension's own); every other CRM_/Civi\\
 * symbol is another extension's internals. Core-shipped extensions (ext/)
 * are included — SearchKit, Afform etc. count as core.
 */
final class CoreNamespaceCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * X in CRM_X_* / CRM_X owned by core.
     *
     * @var list<string>
     */
    public const CRM_COMPONENTS = [
{$renderList($crm)}    ];

    /**
     * X in Civi\\X\\* / Civi\\X owned by core.
     *
     * @var list<string>
     */
    public const CIVI_NAMESPACES = [
{$renderList($civi)}    ];
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/CoreNamespaceCatalog.php';
file_put_contents($target, $out);

fwrite(STDOUT, sprintf(
    "%s: %d CRM components, %d Civi namespaces (CiviCRM %s)\n",
    $target,
    count($crm),
    count($civi),
    $version,
));
