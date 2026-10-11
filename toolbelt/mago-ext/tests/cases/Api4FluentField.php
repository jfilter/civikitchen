<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\Api4Catalog;

// Port of Api4FluentFieldRule: the phpstan Api4FluentFieldRuleTest expectations.
$file = 'phpstan/tests/fixtures/api4-fluent-fields.php';
$field = static fn (string $what, string $clause, int $line): string => sprintf(
    '%s:%d APIv4 field %s does not exist in CiviCRM %s — %s', $file, $line, $what, Api4Catalog::CORE_VERSION, $clause);

return [[
    'name' => 'api4-unknown-field: fluent builder chains and variables',
    'command' => 'analyze',
    'code' => 'civikitchen/api4-unknown-field',
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/api4-stubs.php', 'phpstan/tests/fixtures/generic-stubs.php'],
    'expect' => [
        $field('Contact.street_address', 'addSelect()', 14),
        $field('Contact.postal_code', 'addSelect()', 14),
        $field('Contact.city', 'addSelect()', 14),
        $field('Contact.country_id', 'addSelect()', 14),
        $field('Address.no_such_field', 'address_primary.no_such_field in addSelect()', 47),
        $field('Email.nope', 'email_primary.nope in addWhere()', 47),
        $field('Contact.street_address', 'addSelect()', 83),
        $field('Contact.srot_name', 'addOrderBy()', 99),
        $field('Contact.frist_name', 'addWhere()', 107),
        $field('Contact.frist_name', 'addWhere()', 114),
        $field('Activity.subjcet', 'addSelect()', 116),
        $field('Contact.frist_name', 'addValue()', 118),
        $field('Group.tags', 'addSelect()', 146),
    ],
]];
