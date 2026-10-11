<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\SchemaCatalog;

// Port of SqlTableStaticCallRule and SqlTableMethodCallRule: the phpstan
// SqlTable*RuleTest expectations; both rules report under one code.
$file = 'phpstan/tests/fixtures/sql-tables.php';
$fixtures = dirname(__DIR__, 3) . '/phpstan/tests/fixtures';
$version = SchemaCatalog::CORE_VERSION;
$case = static fn (string $name, string $repo, array $args, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => 'civikitchen/sql-unknown-table',
    'paths' => [$file],
    'args' => $args,
    'env' => ['CK_MAGO_WORKSPACE' => "{$fixtures}/{$repo}"],
    'expect' => array_map(static fn (array $finding): string => "{$file}:{$finding[2]} SQL table {$finding[0]} does not exist in CiviCRM {$version} — {$finding[1]}", $expect),
];
$static = [
    ['civicrm_widget_rule', 'CRM_Core_DAO::executeQuery()', 12],
    ['civicrm_contakt', 'CRM_Core_DAO::singleValueQuery()', 13],
    ['civicrm_emails', 'CRM_Core_DAO::executeUnbufferedQuery()', 14],
    ['civicrm_gadget', 'CRM_Utils_SQL_Select::from()', 17],
    ['civicrm_widget_named', 'CRM_Core_DAO::executeQuery()', 62],
    ['civicrm_widget_cased', 'crm_core_dao::executeQuery()', 63],
    ['civicrm_gadget_cased', 'CRM_Utils_SQL_Select::from()', 64],
    ['civicrm_myext_thing', 'CRM_Core_DAO::executeQuery()', 82],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 83],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 84],
    ['civicrm_emial', 'CRM_Core_DAO::executeQuery()', 85],
    ['civicrm_contact_mirror', 'CRM_Core_DAO::executeQuery()', 86],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 87],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 88],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 89],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 90],
    ['civicrm_contcat', 'CRM_Core_DAO::executeQuery()', 93],
];
$method = [
    ['civicrm_emails', '->join()', 41],
    ['civicrm_widget_rule', '->query()', 44],
    ['civicrm_widget_named', '->query()', 66],
    ['civicrm_emails_named', '->join()', 68],
];
// The repo's schema declares civicrm_widget_rule and civicrm_myext_thing; the option adds the other two.
$declared = ['civicrm_widget_rule', 'civicrm_myext_thing', 'civicrm_contakt', 'civicrm_gadget'];
$undeclared = static fn (array $findings): array => array_values(array_filter($findings, static fn (array $finding): bool => !in_array($finding[0], $declared, true)));

return [
    $case('sql-unknown-table: unknown core tables, static and method calls', 'no-such-repo', [], [...$static, ...$method]),
    $case('sql-unknown-table: the repo schema and --sql-known-tables are silent', 'repo', ['--sql-known-tables=civicrm_contakt,civicrm_gadget'], [...$undeclared($static), ...$undeclared($method)]),
];
