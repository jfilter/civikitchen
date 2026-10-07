<?php

use CiviKitchen\Rector\Tests\Fixture\Helper;

function returned() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    echo $r['count'];
    return $r;
}

function singleId() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    echo $r['count'];
    return $r['id'];
}

function issetEnvelope() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return isset($r['values']) ? $r['values'] : [];
}

function appended() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    $r['values'][] = [];
    return $r['values'];
}

function incremented() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    $r['count']++;
    return $r['values'];
}

function unsetRow() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    unset($r['values'][3]);
    return $r['values'];
}

function sortedInPlace() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    sort($r['values']);
    return $r['values'];
}

function keptByReference() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    Helper::keep($r['values']);
    return $r['values'];
}

function magicMethod() {
    $helper = new Helper();
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    $helper->anything($r['values']);
    return $r['values'];
}

function unknownFunction() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    not_declared_anywhere($r['values']);
    return $r['values'];
}

function foreachByReference() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    foreach ($r['values'] as &$row) {
        $row['seen'] = TRUE;
    }
    return $r['values'];
}

function referenceTaken() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    $values = &$r['values'];
    return $values;
}

function closureUse() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return function () use ($r) {
        return $r['values'];
    };
}

function arrowFunction() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return fn () => $r['values'];
}

function compacted() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    echo $r['count'];
    return compact('r');
}

function variableVariable(string $name) {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    echo $r['count'];
    return $$name;
}

function notAlwaysAssigned(bool $load) {
    if ($load) {
        $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    }
    return $r['values'];
}

function readBeforeAssigned() {
    echo $r['count'];
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return $r['values'];
}

function reassigned() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    echo $r['count'];
    $r = [];
    return $r['values'];
}

function parameter($r) {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return $r['values'];
}

function staticVariable() {
    static $r;
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return $r['values'];
}

function &byReference() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    return $r['values'];
}

function notADaoEntity() {
    $r = civicrm_api3('Setting', 'get', ['domain_id' => 1]);
    return $r['values'];
}

function sequentialNotLiteral(bool $sequential) {
    $r = civicrm_api3('Contact', 'get', ['id' => 3, 'sequential' => $sequential]);
    return $r['values'];
}

function unmappableCall() {
    $r = civicrm_api3('Contact', 'get', ['sort' => 'id DESC']);
    return $r['values'];
}

function passedOn() {
    return civicrm_api3('Contact', 'get', ['id' => 3]);
}

function inArgument() {
    Helper::show(civicrm_api3('Contact', 'get', ['id' => 3]));
}

$top = civicrm_api3('Contact', 'get', ['id' => 3]);
echo $top['count'];

function referenceInForeachList() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    foreach ($r['values'] as ['display_name' => &$name]) {
        $name = trim($name);
    }
    return $r['values'];
}

function referenceInDestructuring() {
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    ['display_name' => &$name] = $r['values'][3];
    $name = 'x';
    return $r['values'];
}

function jumpsOverAssignment(bool $skip) {
    if ($skip) goto later;
    $r = civicrm_api3('Contact', 'get', ['id' => 3]);
    later:
    return $r['count'];
}
