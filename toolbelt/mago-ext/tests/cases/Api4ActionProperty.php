<?php

declare(strict_types=1);

// Port of Api4ActionPropertyRule: the phpstan Api4ActionPropertyRuleTest
// expectations, grouped by finding code.
$file = 'phpstan/tests/fixtures/api4-action-properties.php';
$case = static fn (string $name, string $code, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => $code,
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/generic-stubs.php'],
    'expect' => $expect,
];
$uninitialized = static fn (int $line, string $name, string $type, bool $required = false): string => "{$file}:{$line} "
    . "APIv4 action parameter \${$name} is typed {$type} with no default — a caller that omits it gets "
    . '"must not be accessed before initialization" instead of an API validation error, because '
    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
    . ($required ? ' @required does not prevent this.' : '')
    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
    . 'parameter), or give it a default.';

return [
    $case('api4-uninitialized-action-param: typed parameters without a default', 'civikitchen/api4-uninitialized-action-param', [
        $uninitialized(17, 'contactId', 'int', true),
        $uninitialized(33, 'channel', 'string'),
        $uninitialized(39, 'locale', '?string', true),
        $uninitialized(42, 'signature', '?string'),
        $uninitialized(74, 'fromTrait', 'string'),
        $uninitialized(76, 'any', 'mixed'),
        $uninitialized(78, 'maybe', '?string'),
        $uninitialized(111, 'plain', 'string'),
        $uninitialized(134, 'halfLazy', 'array'),
        $uninitialized(176, 'readInExit', 'string'),
        $uninitialized(178, 'emptyWrongBranch', '?string'),
        $uninitialized(237, 'elseRead', '?string'),
        $uninitialized(239, 'trimmed', '?string'),
        $uninitialized(298, 'orWrong', '?string'),
        $uninitialized(365, 'matchDefault', '?string'),
        $uninitialized(367, 'noElse', 'string'),
        $uninitialized(369, 'andWrong', '?string'),
        $uninitialized(414, 'matchUnsetRead', '?string'),
        $uninitialized(451, 'writeThrough', '?stdClass'),
        $uninitialized(560, 'issetTrueWrong', '?string'),
    ]),
    $case('api4-required-action-param-with-default: @required next to a default', 'civikitchen/api4-required-action-param-with-default', [
        "{$file}:36 APIv4 action parameter \$retries is marked @required but has a default, so the kernel never sees "
        . 'it missing and the requirement is never enforced.',
    ]),
    [
        'name' => 'api4-action-param: trait parameters read from source, every empty default spelling',
        'command' => 'analyze',
        'code' => ['civikitchen/api4-uninitialized-action-param', 'civikitchen/api4-required-action-param-with-default'],
        'paths' => ['mago-ext/tests/fixtures/api4-action-empty-defaults.php'],
        'includes' => ['phpstan/tests/fixtures/generic-stubs.php'],
        'expect' => [
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:36 APIv4 action parameter $traitNullable is typed ?string with no default — '
            . 'a caller that omits it gets "must not be accessed before initialization" instead of an API validation error, because '
            . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required. @required does not prevent this.'
            . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory parameter), or give it a default.",
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:36 APIv4 action parameter $traitSet is marked @required but has a default, '
            . 'so the kernel never sees it missing and the requirement is never enforced.',
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:36 APIv4 action parameter $traitBlank is marked @required but has a default, '
            . 'so the kernel never sees it missing and the requirement is never enforced.',
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:54 APIv4 action parameter $set is marked @required but has a default, '
            . 'so the kernel never sees it missing and the requirement is never enforced.',
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:60 APIv4 action parameter $blank is marked @required but has a default, '
            . 'so the kernel never sees it missing and the requirement is never enforced.',
            // A trait used through another trait counts like a direct one.
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:78 APIv4 action parameter $innerSet is marked @required but has a default, '
            . 'so the kernel never sees it missing and the requirement is never enforced.',
            'mago-ext/tests/fixtures/api4-action-empty-defaults.php:78 APIv4 action parameter $innerUntyped is typed string with no default — '
            . 'a caller that omits it gets "must not be accessed before initialization" instead of an API validation error, because '
            . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
            . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory parameter), or give it a default.",
        ],
    ],
];
