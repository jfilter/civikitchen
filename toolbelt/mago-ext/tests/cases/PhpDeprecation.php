<?php

declare(strict_types=1);

$fixture = 'mago-ext/tests/fixtures/rules/PhpDeprecation.php';
$interpolation = [
    "{$fixture}:13 `\${…}` string interpolation is deprecated since PHP 8.2; write `{\$…}`",
    "{$fixture}:14 `\${…}` string interpolation is deprecated since PHP 8.2; write `{\$…}`",
];
$heredoc = "{$fixture}:29 `\${…}` string interpolation is deprecated since PHP 8.2; write `{\$…}`";

return [[
    'name' => 'php-deprecation: silent on 8.1, which deprecates none of it',
    'command' => 'lint',
    'code' => 'civikitchen/php-deprecation',
    'paths' => [$fixture],
    'expect' => [],
], [
    'name' => 'php-deprecation: 8.2 deprecates `${…}` only',
    'php' => '8.2',
    'command' => 'lint',
    'code' => 'civikitchen/php-deprecation',
    'paths' => [$fixture],
    'expect' => [...$interpolation, $heredoc],
], [
    'name' => 'php-deprecation: 8.3 adds get_class() without an argument',
    'php' => '8.3',
    'command' => 'lint',
    'code' => 'civikitchen/php-deprecation',
    'paths' => [$fixture],
    'expect' => [
        ...$interpolation,
        "{$fixture}:17 get_class() without an argument is deprecated since PHP 8.3; pass the object",
        "{$fixture}:18 get_parent_class() without an argument is deprecated since PHP 8.3; pass the object",
        "{$fixture}:20 get_class() without an argument is deprecated since PHP 8.3; pass the object",
        $heredoc,
    ],
]];
