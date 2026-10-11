<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\TransactionalDdl;

// Port of TransactionalTestDdlRule: the phpstan TransactionalTestDdlRuleTest expectations, grouped by finding code.
$file = 'phpstan/tests/fixtures/transactional-test-ddl.php';
$case = static fn (string $name, string $code, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => 'civikitchen/' . $code,
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/api4-stubs.php', 'phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/civi-test-stubs.php'],
    'expect' => array_map(static fn (array $finding): string => "{$file}:{$finding[1]} " . TransactionalDdl::message($finding[0]), $expect),
];

return [
    $case('test-custom-field-in-transaction: custom fields and groups inside the transaction', 'test-custom-field-in-transaction', [
        ['CustomField::create() in a transactional test', 16],
        ["civicrm_api4('CustomGroup', 'create') in a transactional test", 19],
        ["civicrm_api4('CustomGroup', 'create') in a transactional test", 52],
        ['CustomField::create() in a transactional test', 53],
        ['CustomGroup::delete() in a transactional test', 86],
        ['CustomField::delete() in a transactional test', 87],
        ['CustomField::update() in a transactional test', 88],
        ['CRM_Core_BAO_CustomField::create() in a transactional test', 89],
        ['CRM_Core_BAO_CustomGroup::create() in a transactional test', 90],
        ['CustomField::Create() in a transactional test', 91],
        ["civicrm_api4('CustomField', 'Create') in a transactional test", 92],
        ["civicrm_api3('CustomField', 'create') in a transactional test", 93],
        ["civicrm_api4('CustomGroup', 'create') in a transactional test", 107],
        ['CustomField::create() in a transactional test', 112],
        ['CustomGroup::update() setting is_multiple in a transactional test', 143],
        ["civicrm_api4('CustomGroup', 'update') setting is_multiple in a transactional test", 144],
        ["civicrm_api3('CustomGroup', 'setvalue') setting is_multiple in a transactional test", 145],
        ['CustomGroup::update() setting is_multiple in a transactional test', 146],
        ["civicrm_api3('CustomGroup', 'update') setting is_multiple in a transactional test", 147],
        ["civicrm_api('CustomGroup', 'update') setting is_multiple in a transactional test", 148],
        ["civicrm_api('CustomGroup', 'update') setting is_multiple in a transactional test", 149],
    ]),
    $case('test-ddl-in-transaction: raw DDL inside the transaction', 'test-ddl-in-transaction', [
        ['CREATE statement in a transactional test', 20],
        ['DROP statement in a transactional test', 21],
        ['ALTER statement in a transactional test', 28],
        ['TRUNCATE statement in a transactional test', 54],
        ['RENAME statement in a transactional test', 60],
        ['CREATE statement in a transactional test', 81],
        ['DROP statement in a transactional test', 123],
    ]),
    $case('test-extension-in-transaction: extension lifecycle inside the transaction', 'test-extension-in-transaction', [
        ['extension install() in a transactional test', 23],
    ]),
];
