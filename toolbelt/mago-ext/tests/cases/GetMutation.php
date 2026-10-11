<?php

declare(strict_types=1);

use CiviKitchen\PHPStan\MutationOnGet;

// Port of GetMutationRule: the phpstan GetMutationRuleTest expectations, one case per
// route source (catalog, xml/Menu, none), except StorePage's trait helper (line 141).
$file = 'phpstan/tests/fixtures/get-mutation.php';
$fixtures = dirname(__DIR__, 3) . '/phpstan/tests/fixtures';
$ns = 'CiviKitchen\\Fixtures\\Routes\\';
$case = static fn (string $name, array $args, array $env, array $expect): array => [
    'name' => $name,
    'command' => 'analyze',
    'code' => 'civikitchen/route-mutation-on-get',
    'paths' => [$file],
    'includes' => ['phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/route-stubs.php'],
    'args' => $args,
    'env' => $env,
    'expect' => array_map(
        static fn (array $finding): string => "{$file}:{$finding[3]} " . MutationOnGet::message($finding[0], (str_starts_with($finding[1], 'class@') ? '' : $ns) . $finding[1], $finding[2], '// @mago-expect analysis:civikitchen/route-mutation-on-get'),
        $expect,
    ),
];
$greeter = 'civicrm/greeter/new';
$pagesOnly = [
    ['Widget::create()', 'JsonPage::run()', '', 101],
    ['Widget::create()', 'TraitPage::run()', '', 109],
    ['CRM_Core_BAO_Ledger::writeRecord()', 'LedgerPage::run()', '', 123],
    ['->save() on a DAO/BAO', 'LedgerPage::run()', '', 125],
    ['->delete() on a DAO/BAO', 'LedgerPage::run()', '', 126],
    ['INSERT in ->query()', 'LedgerPage::run()', '', 128],
    ['Widget::create()', 'class@anonymous::run()', '', 162],
    ['Widget::create()', 'class@anonymous::run()', '', 176],
    ['Widget::create()', 'GuardLeakPage::run()', '', 202],
    ['Widget::create()', 'class@anonymous::run()', '', 214],
    ['Widget::create()', 'class@anonymous::run()', '', 225],
    ['Widget::create()', 'BuilderPage::run()', '', 253],
];
$declared = [
    ['Widget::createDraft()', 'GreeterEndpoint::handle()', $greeter, 35],
    ["civicrm_api3('Contact', 'delete')", 'GreeterEndpoint::handle()', $greeter, 36],
    ["civicrm_api4('Contact', 'create')", 'GreeterEndpoint::handle()', $greeter, 37],
    ['UPDATE in crm_core_dao::executeQuery()', 'GreeterEndpoint::handle()', $greeter, 38],
    ['DELETE in CRM_Core_DAO::executeQuery()', 'WidgetPage::run()', 'civicrm/widget', 60],
    ...$pagesOnly,
];

return [
    $case('route-mutation-on-get: writes reachable from a catalogued GET route', ["--route-catalog={$fixtures}/route-catalog.json"], [], $declared),
    $case('route-mutation-on-get: routes from xml/Menu when no catalog exists', [], ['CK_MAGO_WORKSPACE' => "{$fixtures}/repo"], $declared),
    $case('route-mutation-on-get: page classes are routes without any declaration', [], ['CK_MAGO_WORKSPACE' => "{$fixtures}/no-such-repo"], [
        ['DELETE in CRM_Core_DAO::executeQuery()', 'WidgetPage::run()', '', 60],
        ...$pagesOnly,
    ]),
    [
        'name' => 'route-mutation-on-get: a configured catalog that does not exist is an error',
        'command' => 'analyze',
        'code' => 'civikitchen/route-mutation-on-get',
        'paths' => [$file],
        'includes' => ['phpstan/tests/fixtures/generic-stubs.php', 'phpstan/tests/fixtures/route-stubs.php'],
        'args' => ['--route-catalog=no-such-routes.json'],
        'error' => 'no-such-routes.json does not exist',
    ],
];
