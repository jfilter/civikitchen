<?php

declare(strict_types=1);

// Core's Result is an ArrayObject without template types: its row access and
// array cast are not findings. Any other untyped ArrayObject still is.
return [[
    'name' => 'api4-result: row access and array cast on Result are dropped, other ArrayObjects kept',
    'workspace' => 'mago-ext/tests/fixtures/api4-result',
    'command' => 'analyze',
    'code' => ['invalid-array-index', 'invalid-type-cast'],
    'paths' => ['callers.php'],
    'includes' => ['result-stub.php'],
    'expect' => [
        'callers.php:31 Invalid index type `int(0)` used for array access on `Ledger`.',
    ],
]];
