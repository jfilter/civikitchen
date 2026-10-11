<?php

declare(strict_types=1);

$message = 'unserialize() without an allowed_classes option instantiates arbitrary classes from the payload; pass [\'allowed_classes\' => FALSE] (or an explicit class list)';
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'no-unsafe-unserialize: calls and string callables without allowed_classes, modern counterparts silent',
    'command' => 'lint',
    'code' => 'civikitchen/no-unsafe-unserialize',
    'paths' => [$fixtures . 'UnsafeUnserialize.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(static fn (int $line): string => "{$fixtures}UnsafeUnserialize.php:{$line} {$message}", range(11, 27)),
]];
