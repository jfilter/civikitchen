<?php

declare(strict_types=1);

/**
 * Generate the GET-reachable route catalog of one extension.
 *
 * Usage:
 *   php tools/gen-route-catalog.php <extension-dir> [out-file]
 *
 * Reads xml/Menu/*.xml and writes the page_callback targets as JSON. The
 * `ck.route.mutationOnGet` rule reads that file to know which static methods
 * a browser can reach with a plain GET; without it the rule only sees
 * CRM_Core_Page subclasses, whose run() is a route by inheritance.
 *
 * Default output is <extension-dir>/.ck-routes.json, which belongs in the
 * repo: it is generated from files in the same repo, so a diff in it is a
 * routing change and worth reviewing.
 *
 * A page_callback is either `Some\Class::method` (a PSR-7 handler or a
 * static callback) or a bare class name (a CRM_Core_Page/Form, entered
 * through run()/buildQuickForm()).
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-route-catalog.php <extension-dir> [out-file]\n");
    exit(64);
}

$extensionDir = rtrim($argv[1], '/');
$menuDir = $extensionDir . '/xml/Menu';

$routes = [];
foreach (glob($menuDir . '/*.xml') ?: [] as $file) {
    $xml = @simplexml_load_string((string) file_get_contents($file));
    if ($xml === false) {
        fwrite(STDERR, "could not parse $file\n");
        exit(65);
    }
    foreach ($xml->item as $item) {
        $callback = trim((string) $item->page_callback);
        if ($callback === '') {
            continue;
        }
        [$class, $method] = array_pad(explode('::', $callback, 2), 2, null);
        $routes[] = [
            'path' => trim((string) $item->path),
            'class' => ltrim((string) $class, '\\'),
            'method' => $method === null ? null : trim($method),
        ];
    }
}

usort($routes, static fn (array $a, array $b): int => [$a['class'], (string) $a['method']] <=> [$b['class'], (string) $b['method']]);

$target = $argv[2] ?? $extensionDir . '/.ck-routes.json';
$json = json_encode(
    ['routes' => $routes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";

if (file_put_contents($target, $json) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf("%s: %d routes\n", $target, count($routes)));
