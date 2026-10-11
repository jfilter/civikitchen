<?php

declare(strict_types=1);

// Port of CiviKitchen.I18n.UseExtensionTs: the phpcs SniffsTest expectations.
$message = 'Bare ts() resolves in core\'s translation domain — use E::ts() (the civix CRM_<Ext>_ExtensionUtil helper) so the extension\'s own translations apply';
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'use-extension-ts: global ts() calls and callbacks, modern counterparts silent',
    'command' => 'lint',
    'code' => 'civikitchen/use-extension-ts',
    'paths' => [$fixtures . 'BareTs.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(static fn (int $line): string => "{$fixtures}BareTs.php:{$line} {$message}", range(8, 25)),
]];
