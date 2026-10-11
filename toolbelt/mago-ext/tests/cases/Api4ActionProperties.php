<?php

declare(strict_types=1);

// Port of Api4ActionPropertiesExtension: protected action properties, own and
// from a used trait, count as initialized; private, public, `_` and non-actions do not.
$file = 'mago-ext/tests/fixtures/api4-action-initialization.php';
$case = static fn (string $name, string $code, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => $code,
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/generic-stubs.php'],
    'analyzer' => ['check-property-initialization' => true],
    'expect' => $expect,
];
$property = static fn (int $line, string $name, string $class): string =>
    "{$file}:{$line} Property `\${$name}` is not initialized in the constructor of class `CiviKitchen\\Fixtures\\Initialization\\{$class}`.";

return [
    $case('uninitialized-property: only API parameters count as initialized', 'uninitialized-property', [
        $property(20, '_cache', 'FarewellAction'),
        $property(22, 'secret', 'FarewellAction'),
        $property(24, 'notAParam', 'FarewellAction'),
        $property(38, 'channel', 'PlainService'),
    ]),
    $case('missing-constructor: a subclass action inherits its parameters initialized', 'missing-constructor', []),
];
