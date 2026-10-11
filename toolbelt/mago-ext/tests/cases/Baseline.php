<?php

declare(strict_types=1);

// The shipped baseline as a repo's mago.toml extends it: strict-types armed
// everywhere but the test bootstrap, and the extension host table replaceable.
return [[
    'name' => 'baseline: strict-types spares tests/phpunit/bootstrap.php only, nested ones too',
    'command' => 'lint',
    'code' => 'strict-types',
    'baseline' => true,
    'workspace' => 'mago-ext/tests/fixtures/baseline',
    'paths' => ['Plain.php', 'tests/phpunit/bootstrap.php', 'subext/tests/phpunit/bootstrap.php'],
    'expect' => ['Plain.php:1 Missing `declare(strict_types=1);` statement at the beginning of the file.'],
]];
