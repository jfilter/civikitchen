<?php

declare(strict_types=1);

$file = 'mago-ext/tests/fixtures/rules/ImplicitVisibility.php';

return [[
    'name' => 'explicit-visibility: methods and properties without visibility, var, several properties per declaration',
    'command' => 'lint',
    'code' => 'civikitchen/explicit-visibility',
    'paths' => [$file, 'mago-ext/tests/fixtures/rules/CleanModern.php'],
    'expect' => [
        "{$file}:7 The var keyword must not be used to declare a property",
        "{$file}:7 Visibility must be declared on property \"\$legacy\"",
        "{$file}:9 Visibility must be declared on property \"\$counter\"",
        "{$file}:11 There must not be more than one property declared per statement",
        "{$file}:15 Visibility must be declared on property \"\$implicit\"",
        "{$file}:21 Visibility must be declared on method \"implicitMethod\"",
        "{$file}:33 Visibility must be declared on method \"implicitInterfaceMethod\"",
        "{$file}:43 Visibility must be declared on method \"implicitTraitMethod\"",
        "{$file}:53 Visibility must be declared on method \"implicitEnumMethod\"",
        "{$file}:62 The var keyword must not be used to declare a property",
        "{$file}:62 Visibility must be declared on property \"\$anonymous\"",
        "{$file}:64 Visibility must be declared on method \"implicitAnonymousMethod\"",
    ],
]];
