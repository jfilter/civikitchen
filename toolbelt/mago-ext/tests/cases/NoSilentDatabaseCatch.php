<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\DatabaseCatch;

// Port of NoSilentDatabaseCatchRule: the phpstan NoSilentDatabaseCatchRuleTest expectations.
$file = 'phpstan/tests/fixtures/silent-database-catch.php';
$silent = DatabaseCatch::message(false);
$empty = DatabaseCatch::message(true);

return [[
    'name' => 'db-silent-catch: only catches that swallow query failures',
    'command' => 'analyze',
    'code' => 'civikitchen/db-silent-catch',
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/api4-stubs.php', 'phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/sql-stubs.php'],
    'expect' => [
        "{$file}:16 {$silent}",
        "{$file}:25 {$empty}",
        "{$file}:34 {$silent}",
        "{$file}:65 {$empty}",
        "{$file}:103 {$silent}",
        "{$file}:113 {$empty}",
        "{$file}:118 {$empty}",
    ],
]];
