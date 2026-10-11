<?php

declare(strict_types=1);

// mago's built-in strict-types in place of SlevomatCodingStandard.TypeHints.DeclareStrictTypes:
// a missing declare is reported on line 1, CleanModern's spaced `strict_types = 1` passes.
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'strict-types: missing declare on line 1, the spaced declare accepted',
    'command' => 'lint',
    'code' => 'strict-types',
    'paths' => [$fixtures . 'MissingStrictTypes.php', $fixtures . 'CleanModern.php'],
    'expect' => [$fixtures . 'MissingStrictTypes.php:1 Missing `declare(strict_types=1);` statement at the beginning of the file.'],
]];
