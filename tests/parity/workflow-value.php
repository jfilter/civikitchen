<?php

declare(strict_types=1);

// Prints the value at a key path of a workflow (`jobs ci strategy max-parallel`), a string
// as it is and anything else as JSON. A list such as `steps` is indexed by its items' `name`.
require __DIR__ . '/workflow-jobs.php';

$file = $argv[1] ?? '';
$path = array_slice($argv, 2);
$value = ck_workflow_parsed($file);
foreach ($path as $key) {
    if (is_array($value) && array_key_exists($key, $value)) {
        $value = $value[$key];
        continue;
    }
    $named = is_array($value)
        ? array_filter($value, static fn ($item): bool => is_array($item) && ($item['name'] ?? null) === $key)
        : [];
    if ($named === []) {
        fwrite(STDERR, "no '" . implode(' ', $path) . "' in {$file}\n");
        exit(1);
    }
    $value = reset($named);
}
echo is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
