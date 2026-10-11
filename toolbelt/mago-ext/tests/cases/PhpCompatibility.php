<?php

declare(strict_types=1);

$fixture = 'mago-ext/tests/fixtures/rules/PhpCompatibility.php';

return [[
    'name' => 'php-compatibility: 8.2+ syntax and unpolyfilled functions at an 8.1 floor, an imported alias too',
    'command' => 'lint',
    'code' => 'civikitchen/php-compatibility',
    'paths' => [$fixture],
    'expect' => [
        "{$fixture}:15 A dynamic class constant fetch `C::{\$name}` needs PHP 8.3, the floor is 8.1; use constant()",
        "{$fixture}:18 request_parse_body() exists since PHP 8.4, the floor is 8.1",
        "{$fixture}:19 curl_multi_get_handles() exists since PHP 8.5, the floor is 8.1",
        "{$fixture}:20 posix_eaccess() exists since PHP 8.3, the floor is 8.1",
        "{$fixture}:22 request_parse_body() exists since PHP 8.4, the floor is 8.1",
        "{$fixture}:29 mysqli_execute_query() exists since PHP 8.2, the floor is 8.1",
    ],
], [
    'name' => 'php-compatibility: a function_exists() guard silences the call',
    'command' => 'lint',
    'code' => 'civikitchen/php-compatibility',
    'paths' => ['mago-ext/tests/fixtures/rules/PhpCompatibilityGuarded.php'],
    'expect' => [],
], [
    'name' => 'php-compatibility: at an 8.4 floor only the 8.5 function remains',
    'php' => '8.4',
    'command' => 'lint',
    'code' => 'civikitchen/php-compatibility',
    'paths' => [$fixture],
    'expect' => [
        "{$fixture}:19 curl_multi_get_handles() exists since PHP 8.5, the floor is 8.4",
    ],
], [
    'name' => 'php-compatibility: a PHP version check covers functions up to the version it names',
    'command' => 'lint',
    'code' => 'civikitchen/php-compatibility',
    'paths' => ['mago-ext/tests/fixtures/rules/PhpCompatibilityVersioned.php'],
    'expect' => [
        'mago-ext/tests/fixtures/rules/PhpCompatibilityVersioned.php:12 curl_multi_get_handles() exists since PHP 8.5, the floor is 8.1',
    ],
]];
