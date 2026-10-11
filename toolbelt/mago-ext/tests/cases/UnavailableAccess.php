<?php

declare(strict_types=1);

return [[
    'name' => 'unavailable-access: constants, enum cases and static calls of what the floor lacks',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => ['civikitchen/unavailable-access', 'unavailable-method'],
    'paths' => ['access.php', 'access-silent.php'],
    // mago names the newer method itself (line 26); the class is not repeated.
    'expect' => [
        'access.php:11 The class `Random\\IntervalBoundary` is not available in PHP 8.1.',
        'access.php:16 The class `Random\\IntervalBoundary` is not available in PHP 8.1.',
        'access.php:21 The class `Pdo\\Sqlite` is not available in PHP 8.1.',
        'access.php:21 The constant `CURLOPT_PREREQFUNCTION` is not available in PHP 8.1.',
        'access.php:21 The constant `CURLOPT_TCP_KEEPCNT` is not available in PHP 8.1.',
        'access.php:26 The method `dom\\htmldocument::createempty` is not available in PHP 8.1.',
    ],
], [
    'name' => 'unavailable-access: off without --compatibility',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'code' => 'civikitchen/unavailable-access',
    'paths' => ['access.php'],
    'expect' => [],
]];
