<?php

declare(strict_types=1);

// As ckanalyze reads core: the stub makes Result generic over its rows, a
// provider and a patch type the rows core returns; its array cast is no finding.
return [[
    'name' => 'api4-result: index access on Result and a subclass without row type pass, its array cast dropped, other ArrayObjects kept',
    'workspace' => 'mago-ext/tests/fixtures/api4-result',
    'command' => 'analyze',
    'code' => ['invalid-array-index', 'invalid-type-cast', 'missing-template-parameter'],
    'paths' => ['callers.php'],
    'includes' => ['{toolbelt}/mago/stubs', 'core.php'],
    'patches' => ['{toolbelt}/mago/patches'],
    'expect' => [
        'callers.php:30 Too few template arguments for `ArrayObject`: expected at least 2, but found 0.',
        'callers.php:34 Object of type `Ledger` cast to `array`. Property visibility (public, protected, private) affects the resulting array.',
        'callers.php:38 Invalid index type `int(0)` used for array access on `Ledger`.',
    ],
], [
    'name' => 'api4-result: execute() rows are field maps unless the action declares its own execute(), or the shape a Result<…> names; an untyped Result takes any value',
    'workspace' => 'mago-ext/tests/fixtures/api4-result',
    'command' => 'analyze',
    'code' => ['invalid-return-statement', 'nullable-return-statement', 'mixed-return-statement', 'mixed-assignment', 'mixed-array-access', 'possibly-null-array-access', 'invalid-array-index', 'invalid-array-access-assignment-value', 'invalid-argument', 'less-specific-argument', 'null-argument', 'non-existent-property', 'incompatible-return-type', 'incompatible-parameter-type', 'never-return', 'invalid-property-access'],
    'paths' => ['rows.php'],
    'includes' => ['{toolbelt}/mago/stubs', 'core.php'],
    'patches' => ['{toolbelt}/mago/patches'],
    'expect' => [
        'rows.php:12 Invalid return type for function `LedgerRows::each`: expected `string`, but found `array<string, mixed>`.',
        'rows.php:18 Invalid return type for function `LedgerRows::single`: expected `string`, but found `array<string, mixed>`.',
        'rows.php:22 Function `LedgerRows::first` is declared to return `string` but possibly returns a nullable value (inferred as `array<string, mixed>|null`).',
        'rows.php:22 Invalid return type for function `LedgerRows::first`: expected `string`, but found `array<string, mixed>|null`.',
        'rows.php:26 Invalid return type for function `LedgerRows::fetched`: expected `string`, but found `array<string, mixed>`.',
        'rows.php:30 Could not infer a precise return type for function `LedgerRows::fields`. Saw type `mixed`.',
        'rows.php:34 Could not infer a precise return type for function `LedgerRows::titles`. Saw type `mixed`.',
        'rows.php:49 Invalid return type for function `LedgerRows::typedEach`: expected `string`, but found `int`.',
        'rows.php:58 Function `LedgerRows::typedIndex` is declared to return `string` but possibly returns a nullable value (inferred as `null|string`).',
        'rows.php:58 Invalid return type for function `LedgerRows::typedIndex`: expected `string`, but found `null|string`.',
        "rows.php:65 Function `LedgerRows::typedIndexBy` is declared to return `string` but possibly returns a nullable value (inferred as `array{'id': int, 'title': null|string}|null`).",
        "rows.php:65 Invalid return type for function `LedgerRows::typedIndexBy`: expected `string`, but found `array{'id': int, 'title': null|string}|null`.",
        'rows.php:72 Invalid return type for function `LedgerRows::rekeyed`: expected `string`, but found `array<array-key, mixed>`.',
        'rows.php:126 Invalid return type for function `LedgerRows::inherited`: expected `string`, but found `array<string, mixed>`.',
    ],
]];
