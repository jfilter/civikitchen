<?php

declare(strict_types=1);

// Port of CiviKitchen.Api.NoRequiredOnExternalAction: the phpcs SniffsTest expectations.
$fixtures = 'mago-ext/tests/fixtures/rules/';
$required = static fn (string $file, int $line): string => sprintf(
    '%s%s:%d @required is forbidden on the externally reachable action RequiredOnIntake — enforce requiredness as a rejected '
    . 'verdict in the validation method, never as an APIv4 exception (a proxy HTTP 500 is retried forever)',
    $fixtures, $file, $line);
$case = static fn (string $name, array $files, array $args, array $expect): array => [
    'name' => "no-required-on-external-action: {$name}",
    'command' => 'lint',
    'code' => 'civikitchen/no-required-on-external-action',
    'paths' => array_map(static fn (string $file): string => $fixtures . $file, $files),
    'args' => $args,
    'expect' => $expect,
];

return [
    // Without --external-actions the rule never guesses which actions are external.
    $case('inert without configured actions', ['RequiredOnIntake.php', 'RequiredOnImporter.php', 'CleanModern.php'], [], []),
    // The enclosing class decides; the trait's @required (line 19) is outside the class body.
    $case('flags only the configured action', ['RequiredOnIntake.php', 'RequiredOnImporter.php', 'RequiredOnSecondClass.php', 'CleanModern.php'],
        ['--external-actions=RequiredOnIntake'],
        [$required('RequiredOnIntake.php', 10), $required('RequiredOnSecondClass.php', 31)]),
    $case('qualified entry matches by namespace', ['RequiredNamespaced.php'], ['--external-actions=\\Acme\\Api\\RequiredOnIntake'],
        [$required('RequiredNamespaced.php', 26)]),
];
