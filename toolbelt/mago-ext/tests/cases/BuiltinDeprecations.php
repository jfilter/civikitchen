<?php

declare(strict_types=1);

$codes = ['deprecated-function', 'deprecated-method', 'deprecated-class'];
$builtin = [
    'project.php:25 Call to deprecated method: `ReflectionProperty::setAccessible`.',
    'project.php:28 Call to deprecated function: `utf8_encode`.',
];

return [[
    'name' => 'builtin-deprecations: --compatibility=8.5 keeps only what PHP deprecates',
    'workspace' => 'mago-ext/tests/fixtures/deprecations',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => $codes,
    'paths' => ['project.php'],
    'expect' => $builtin,
], [
    'name' => 'builtin-deprecations: a deprecation PHP dates after the newest version checked goes',
    'workspace' => 'mago-ext/tests/fixtures/deprecations',
    'command' => 'analyze',
    'args' => ['--compatibility=8.3'],
    'code' => $codes,
    'paths' => ['project.php'],
    'expect' => ['project.php:28 Call to deprecated function: `utf8_encode`.'],
], [
    'name' => 'builtin-deprecations: the project\'s own deprecated trait, interface and constant go',
    'workspace' => 'mago-ext/tests/fixtures/deprecations',
    'command' => 'analyze',
    'args' => ['--compatibility=8.5'],
    'code' => ['deprecated-trait', 'deprecated-class', 'deprecated-constant'],
    'paths' => ['kinds.php'],
    'expect' => [],
], [
    'name' => 'builtin-deprecations: without the option the project\'s own deprecated trait stands',
    'workspace' => 'mago-ext/tests/fixtures/deprecations',
    'command' => 'analyze',
    'code' => ['deprecated-trait', 'deprecated-class', 'deprecated-constant'],
    'paths' => ['kinds.php'],
    'expect' => ['kinds.php:19 Use of deprecated trait `Acme\\Widget\\OldGreeting` in `Acme\\Widget\\Thing`'],
], [
    'name' => 'builtin-deprecations: without the option the project\'s own deprecations stand',
    'workspace' => 'mago-ext/tests/fixtures/deprecations',
    'command' => 'analyze',
    'code' => $codes,
    'paths' => ['project.php'],
    'expect' => [
        'project.php:24 Call to deprecated method: `Legacy::old`.',
        ...$builtin,
        'project.php:26 Class `Legacy` is deprecated and should no longer be used.',
        'project.php:28 Call to deprecated function: `legacyHelper`.',
    ],
]];
