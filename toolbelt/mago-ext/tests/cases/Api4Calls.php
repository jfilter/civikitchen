<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\Api4Catalog;

// Port of Api4FunctionCallRule, Api4StaticCallRule and Api4StaticCallableRule:
// the phpstan Api4CallRulesTest expectations, grouped by finding code.
$file = 'phpstan/tests/fixtures/api4-calls.php';
$version = Api4Catalog::CORE_VERSION;
$case = static fn (string $name, string $code, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => $code,
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/api4-stubs.php', 'phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/api4-function-stub.php'],
    'expect' => $expect,
];

$names = 'phpstan/tests/fixtures/api4-call-names.php';

return [
    [
        'name' => 'api4-unknown-action: civicrm_api4() by resolved name, not spelling',
        'command' => 'analyze',
        'code' => 'civikitchen/api4-unknown-action',
        'paths' => [$names],
        'includes' => ['phpstan/tests/fixtures/api4-stubs.php', 'phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/api4-function-stub.php'],
        'expect' => [
            "{$names}:10 APIv4 action Contact::gett does not exist in CiviCRM {$version} — civicrm_api4()",
            "{$names}:11 APIv4 action Contact::gett does not exist in CiviCRM {$version} — civicrm_api4()",
        ],
    ],
    $case('api4-unknown-field: civicrm_api4() params', 'civikitchen/api4-unknown-field', [
        "{$file}:13 APIv4 field Contact.display_nam does not exist in CiviCRM {$version} — select",
        "{$file}:16 APIv4 field Contact.contact_typ does not exist in CiviCRM {$version} — groupBy",
        "{$file}:36 APIv4 field Contact.display_nam does not exist in CiviCRM {$version} — select",
        "{$file}:52 APIv4 field Contact.frist_name does not exist in CiviCRM {$version} — select",
        "{$file}:55 APIv4 field Group.tags does not exist in CiviCRM {$version} — select",
    ]),
    $case('api4-unknown-action: civicrm_api4(), static calls and callables', 'civikitchen/api4-unknown-action', [
        "{$file}:14 APIv4 action Contact::gett does not exist in CiviCRM {$version} — civicrm_api4()",
        "{$file}:37 APIv4 action Contact::gett does not exist in CiviCRM {$version} — civicrm_api4()",
        "{$file}:15 APIv4 action Contact::gett does not exist in CiviCRM {$version} — fluent APIv4 call",
        "{$file}:45 APIv4 action Contact::gett does not exist in CiviCRM {$version} — fluent APIv4 call",
        "{$file}:73 APIv4 action Contact::gte does not exist in CiviCRM {$version} — fluent APIv4 call",
    ]),
    $case('api4-unknown-entity: near-misses of core entities', 'civikitchen/api4-unknown-entity', [
        "{$file}:66 APIv4 entity Contatc does not exist in CiviCRM {$version} — did you mean Contact? (civicrm_api4())",
        "{$file}:67 APIv4 entity contact does not exist in CiviCRM {$version} — did you mean Contact? (civicrm_api4())",
    ]),
];
