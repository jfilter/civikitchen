<?php

declare(strict_types=1);

$fixtures = 'mago-ext/tests/fixtures/rules/';
$guidance = [
    'managed' => 'move managed entity definitions to managed/*.mgd.php and enable the mgd-php mixin',
    'navigationMenu' => 'declare menu entries as managed Navigation records in managed/*.mgd.php and enable the mgd-php mixin',
    'xmlMenu' => 'move routes to xml/Menu/*.xml and enable the menu-xml mixin',
    'caseTypes' => 'move case types to xml/case/*.xml and enable the case-xml mixin',
    'themes' => 'move theme definitions to *.theme.php and enable the theme-php mixin',
    'alterSettingsFolders' => 'move setting metadata to settings/*.setting.php and enable the setting-php mixin',
    'entityTypes' => 'move entity type definitions to *.entityType.php and enable entity-types-php@2.0.0',
    'angularModules' => 'move Angular module metadata to ang/*.ang.php and enable the ang-php mixin',
];
$hook = static fn (string $file, int $line, string $function, string $hook): string
    => "{$fixtures}{$file}:{$line} Legacy hook {$function}() is banned in modern extensions — {$guidance[$hook]}";
$case = static fn (string $name, string $file, array $expect): array => [
    'name' => "use-mixins-for-standard-hooks: {$name}",
    'command' => 'lint',
    'code' => 'civikitchen/use-mixins-for-standard-hooks',
    'paths' => [$fixtures . $file],
    'expect' => $expect,
];

return [
    // No info.xml above the fixture: any _<hook> suffix counts, the helper on line 34 included.
    $case('suffix match without info.xml', 'LegacyMixinHooks.php', [
        $hook('LegacyMixinHooks.php', 7, 'civikitchen_fixture_civicrm_managed', 'managed'),
        $hook('LegacyMixinHooks.php', 10, 'civikitchen_fixture_civicrm_navigationMenu', 'navigationMenu'),
        $hook('LegacyMixinHooks.php', 16, 'civikitchen_fixture_civicrm_entityTypes', 'entityTypes'),
        $hook('LegacyMixinHooks.php', 19, 'civikitchen_fixture_civicrm_angularModules', 'angularModules'),
        $hook('LegacyMixinHooks.php', 22, 'civikitchen_fixture_civicrm_xmlMenu', 'xmlMenu'),
        $hook('LegacyMixinHooks.php', 25, 'civikitchen_fixture_civicrm_caseTypes', 'caseTypes'),
        $hook('LegacyMixinHooks.php', 28, 'civikitchen_fixture_civicrm_themes', 'themes'),
        $hook('LegacyMixinHooks.php', 31, 'civikitchen_fixture_civicrm_alterSettingsFolders', 'alterSettingsFolders'),
        $hook('LegacyMixinHooks.php', 34, 'civikitchen_fixture_helper_civicrm_managed', 'managed'),
    ]),
    // acme_civicrm_<hook> in any case, also inside the function_exists() guard.
    $case('info.xml prefix', 'mixin-ext/acme.php', [
        $hook('mixin-ext/acme.php', 6, 'acme_civicrm_managed', 'managed'),
        $hook('mixin-ext/acme.php', 11, 'Acme_Civicrm_XmlMenu', 'xmlMenu'),
        $hook('mixin-ext/acme.php', 14, 'acme_civicrm_caseTypes', 'caseTypes'),
        $hook('mixin-ext/acme.php', 17, 'acme_civicrm_themes', 'themes'),
        $hook('mixin-ext/acme.php', 20, 'acme_civicrm_alterSettingsFolders', 'alterSettingsFolders'),
        $hook('mixin-ext/acme.php', 24, 'acme_civicrm_navigationMenu', 'navigationMenu'),
    ]),
    $case('only global functions', 'mixin-ext/namespaced.php', [
        $hook('mixin-ext/namespaced.php', 13, 'acme_civicrm_caseTypes', 'caseTypes'),
    ]),
    $case('modern counterparts silent', 'CleanModern.php', []),
];
