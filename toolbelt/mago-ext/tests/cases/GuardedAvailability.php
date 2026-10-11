<?php

declare(strict_types=1);

$codes = ['unavailable-function', 'unavailable-class-like', 'unavailable-method'];
// A class guard on a class older than the floor does not vouch for its newer method.
$newMethod = 'guarded.php:17 The method `datetimeimmutable::createfromtimestamp` is not available in PHP 8.1.';
$unguarded = [
    'unguarded.php:12 The method `dom\\htmldocument::createempty` is not available in PHP 8.1.',
    'unguarded.php:7 The function `str_increment` is not available in PHP 8.1.',
];

return [[
    'name' => 'guarded-availability: --compatibility drops what the file guards',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => $codes,
    'paths' => ['guarded.php', 'unguarded.php'],
    'expect' => [$newMethod, ...$unguarded],
], [
    'name' => 'guarded-availability: a PHP version check above the floor, an aliased class guard and an expression method guard',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => $codes,
    'paths' => ['versioned.php', 'aliased.php'],
    'expect' => [],
], [
    'name' => 'guarded-availability: a guard in a comment, for another namespace\'s class or another class\'s method does not count',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => $codes,
    'paths' => ['misguarded.php'],
    'expect' => [
        'misguarded.php:15 The method `dom\\htmldocument::createempty` is not available in PHP 8.1.',
        'misguarded.php:20 The method `datetimeimmutable::createfromtimestamp` is not available in PHP 8.1.',
        'misguarded.php:10 The function `str_increment` is not available in PHP 8.1.',
    ],
], [
    'name' => 'guarded-availability: without the option every unavailable call stands',
    'workspace' => 'mago-ext/tests/fixtures/compatibility',
    'command' => 'analyze',
    'code' => $codes,
    'paths' => ['guarded.php', 'unguarded.php'],
    'expect' => [
        'guarded.php:12 The method `dom\\htmldocument::createempty` is not available in PHP 8.1.',
        $newMethod,
        'guarded.php:7 The function `str_increment` is not available in PHP 8.1.',
        ...$unguarded,
    ],
]];
