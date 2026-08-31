<?php

declare(strict_types=1);

$report = $argv[1] ?? '';
$minimum = isset($argv[2]) ? (float) $argv[2] : 0.0;
$xml = is_file($report) ? simplexml_load_file($report) : false;
if (!$xml instanceof SimpleXMLElement || !$xml->project->metrics instanceof SimpleXMLElement) {
    fwrite(STDERR, "shared PHP coverage: missing or invalid Clover report\n");
    exit(2);
}
$metrics = $xml->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($total === 0) {
    fwrite(STDERR, "shared PHP coverage: no executable statements measured\n");
    exit(2);
}
$source = realpath($argv[3] ?? dirname(__DIR__, 2) . '/toolbelt/lib/php/src');
$measured = [];
$untested = [];
foreach ($xml->project->file as $file) {
    $name = realpath((string) $file['name']);
    if ($name !== false && $source !== false && str_starts_with($name, $source . DIRECTORY_SEPARATOR)) {
        $measured[$name] = true;
        $fileMetrics = $file->metrics;
        if ($fileMetrics instanceof SimpleXMLElement
            && (int) $fileMetrics['statements'] > 0
            && (int) $fileMetrics['coveredstatements'] === 0) {
            $untested[] = $name;
        }
    }
}
$expected = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $expected[$file->getRealPath()] = true;
    }
}
$missing = array_keys(array_diff_key($expected, $measured));
if ($missing !== []) {
    fwrite(STDERR, "shared PHP coverage: source files missing from report:\n  " . implode("\n  ", $missing) . "\n");
    exit(2);
}
if ($untested !== []) {
    fwrite(STDERR, "shared PHP coverage: executable files with zero covered statements:\n  " . implode("\n  ", $untested) . "\n");
    exit(1);
}
$percentage = $covered * 100 / $total;
printf("shared PHP coverage: %.2f%% (%d/%d statements across %d files)\n", $percentage, $covered, $total, count($expected));
if ($percentage + 0.00001 < $minimum) {
    fwrite(STDERR, sprintf("shared PHP coverage: FAIL - %.2f%% is below the %.2f%% floor\n", $percentage, $minimum));
    exit(1);
}
