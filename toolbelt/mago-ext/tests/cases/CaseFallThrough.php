<?php

declare(strict_types=1);

$message = 'End the case with break, or put a comment before the next case to mark the fall-through as meant.';
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'case-fall-through: unmarked fall-through; leaving statements, branches, comments, default and the last case silent',
    'command' => 'lint',
    'code' => 'civikitchen/case-fall-through',
    'paths' => [$fixtures . 'CaseFallThrough.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(static fn (int $line): string => "{$fixtures}CaseFallThrough.php:{$line} {$message}", [10, 26, 30, 50, 81, 91]),
]];
