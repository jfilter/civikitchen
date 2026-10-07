<?php

/**
 * Asks the phpstan field check about every live get and create field on a
 * booted site of the catalog's minor release.
 *
 * Run with `cv scr`, CK_PHPSTAN_SRC pointing at toolbelt/phpstan/src. A live
 * field the check rejects is a false unknownField report, so the run fails.
 * Entities of extensions the site has not enabled are listed, not checked.
 */

declare(strict_types=1);

use CiviKitchen\PHPStan\Api4Catalog;
use CiviKitchen\PHPStan\Api4Contract;

$src = getenv('CK_PHPSTAN_SRC');
if ($src === false || !is_file("{$src}/Api4Catalog.php") || !is_file("{$src}/Api4Contract.php")) {
    fwrite(STDERR, "live-api4-drift: CK_PHPSTAN_SRC must name toolbelt/phpstan/src\n");
    exit(2);
}
require "{$src}/Api4Catalog.php";
require "{$src}/Api4Contract.php";

$minor = static fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2));
$site = CRM_Utils_System::version();
if ($minor($site) !== $minor(Api4Catalog::CORE_VERSION)) {
    echo 'live-api4-drift: skipped, the catalog is ' . Api4Catalog::CORE_VERSION . ", the site {$site}\n";
    exit(0);
}

$offered = array_flip(array_column((array) civicrm_api4('Entity', 'get', [
    'select' => ['name'],
    'checkPermissions' => FALSE,
]), 'name'));

$checked = 0;
$absent = [];
$rejected = [];
foreach (array_keys(Api4Catalog::ENTITIES) as $entity) {
    if (!Api4Catalog::hasCompleteFields($entity)) {
        continue;
    }
    if (!isset($offered[$entity])) {
        $absent[] = $entity;
        continue;
    }
    // A create-only spec provider adds fields getFields(get) never lists.
    foreach (['get' => 'where', 'create' => 'values'] as $action => $clause) {
        if (!in_array($action, explode(' ', Api4Catalog::ENTITIES[$entity]['a']), true)) {
            continue;
        }
        $fields = civicrm_api4($entity, 'getFields', [
            'action' => $action,
            'select' => ['name'],
            'checkPermissions' => FALSE,
        ]);
        foreach ($fields as $field) {
            if (Api4Contract::rejectsField($entity, $field['name'], $clause)) {
                $rejected["{$entity}.{$field['name']}"] = true;
            }
        }
    }
    $checked++;
}

if ($absent !== []) {
    echo 'live-api4-drift: not enabled on this site: ' . implode(' ', $absent) . "\n";
}
if ($checked === 0) {
    echo "live-api4-drift: FAIL - no catalog entity was checked on {$site}\n";
    exit(1);
}
if ($rejected !== []) {
    echo 'live-api4-drift: FAIL - live fields the check rejects: ' . implode(' ', array_keys($rejected)) . "\n";
    exit(1);
}
echo "live-api4-drift: {$checked} entities' get and create fields accepted on {$site}\n";
