<?php

declare(strict_types=1);

// --disable and --exclude, the per-repo switches a repo's own mago.toml passes to the worker;
// exclude globs match the workspace-relative path.
$fixtures = 'mago-ext/tests/fixtures/rules/';
$paths = array_map(static fn (string $file): string => $fixtures . $file, ['LongFile.php', 'ShortPhp.php', 'TemplateEcho.php', 'TemplateHtml.php']);

return [
    [
        'name' => 'rule options: --exclude skips matching files for that rule only',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'args' => ['--max-lines=10', '--exclude=civikitchen/max-file-length:mago-ext/tests/fixtures/rules/Template*.php,civikitchen/use-extension-ts:*/LongFile.php'],
        'paths' => $paths,
        'expect' => [
            "{$fixtures}LongFile.php:1 File is 18 lines long; the maximum is 10. Split it into focused classes.",
            "{$fixtures}ShortPhp.php:1 File is 11 lines long; the maximum is 10. Split it into focused classes.",
        ],
    ],
    [
        'name' => 'rule options: --disable drops the rule',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'args' => ['--max-lines=10', '--disable=civikitchen/max-file-length'],
        'paths' => $paths,
        'expect' => [],
    ],
    [
        'name' => 'rule options: an unknown rule code stops the run',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'args' => ['--disable=civikitchen/max-file-lenght'],
        'paths' => $paths,
        'error' => 'no linter rule civikitchen/max-file-lenght',
    ],
    [
        'name' => 'rule options: an exclude without a glob stops the run',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'args' => ['--exclude=civikitchen/max-file-length'],
        'paths' => $paths,
        'error' => 'needs <code>:<glob>',
    ],
];
