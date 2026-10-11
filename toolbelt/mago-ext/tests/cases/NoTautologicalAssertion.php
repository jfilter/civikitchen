<?php

declare(strict_types=1);

$fixtures = 'mago-ext/tests/fixtures/rules/';
$names = [
    12 => 'assertTrue', 13 => 'assertTrue', 14 => 'assertFalse', 15 => 'assertNotFalse', 16 => 'assertTrue',
    17 => 'assertTrue', 18 => 'assertTrue', 19 => 'assertTrue', 20 => 'assertTrue', 21 => 'assertTrue',
    22 => 'assertFalse', 23 => 'assertTrue', 26 => 'assertTrue', 27 => 'assertTrue', 28 => 'assertNull',
    29 => 'assertSame', 30 => 'assertEquals', 31 => 'assertSame', 32 => 'assertEmpty', 33 => 'assertEmpty',
    34 => 'assertSame', 35 => 'assertNotTrue',
];

return [[
    'name' => 'no-tautological-assertion: literal outcomes, modern counterparts silent',
    'command' => 'lint',
    'code' => 'civikitchen/no-tautological-assertion',
    'paths' => [$fixtures . 'TautologicalAssertion.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(
        static fn (int $line, string $name): string => "{$fixtures}TautologicalAssertion.php:{$line} Tautological assertion {$name}() passes regardless of the code under test; use \$this->expectNotToPerformAssertions() or assert the postcondition",
        array_keys($names),
        $names,
    ),
]];
