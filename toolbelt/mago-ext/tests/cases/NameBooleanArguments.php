<?php

declare(strict_types=1);

$fixtures = 'mago-ext/tests/fixtures/rules/';
$flagged = [
    9 => ['TRUE', 'civikitchen_save'], 10 => ['FALSE', 'civikitchen_save'], 18 => ['TRUE', 'setFlags'],
    19 => ['TRUE', 'civikitchen_settle'], 32 => ['TRUE', 'run'], 33 => ['TRUE', 'ArrayObject'], 34 => ['TRUE', 'run'],
];

return [[
    'name' => 'name-boolean-arguments: bare positional literals only, modern counterparts silent',
    'command' => 'lint',
    'code' => 'civikitchen/name-boolean-arguments',
    'paths' => [$fixtures . 'BooleanArgs.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(
        static fn (int $line, array $hit): string => "{$fixtures}BooleanArgs.php:{$line} Positional {$hit[0]} says nothing at the call site — name the argument ({$hit[1]}(… , flag: {$hit[0]}))",
        array_keys($flagged),
        $flagged,
    ),
]];
