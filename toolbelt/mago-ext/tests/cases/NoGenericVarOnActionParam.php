<?php

declare(strict_types=1);

// Port of CiviKitchen.Api.NoGenericVarOnActionParam: the phpcs SniffsTest expectations.
$fixtures = 'mago-ext/tests/fixtures/rules/';
$var = static fn (int $line, string $param, string $word): string => sprintf(
    '%sGenericActionVar.php:%d APIv4 action param $%s has @var "%s", which core cannot validate at runtime ("Unknown parameter type") '
    . '— use array, bool, string, int, float or mixed (optionally |null) and put the precise type in @phpstan-var',
    $fixtures, $line, $param, $word);

return [[
    'name' => 'no-generic-var-on-action-param: mirrors core\'s type check, modern counterparts silent',
    'command' => 'lint',
    'code' => 'civikitchen/no-generic-var-on-action-param',
    'paths' => [$fixtures . 'GenericActionVar.php', $fixtures . 'CleanModern.php'],
    'expect' => [
        $var(13, 'rows', 'array<int,'),
        $var(18, 'ids', 'int[]'),
        $var(23, 'label', 'non-empty-string'),
        $var(28, 'maybe', '?string'),
        $var(33, 'count', 'integer'),
        $var(38, 'thing', 'object'),
        $var(113, 'options', 'array<string,'),
        $var(126, 'extra', 'list<int>'),
        $var(135, 'names', 'string[]'),
    ],
]];
