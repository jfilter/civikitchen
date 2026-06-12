<?php

// Fixture: the modern counterparts of everything the footgun sniffs ban.
// The test asserts ZERO findings from the CiviKitchen sniffs here — every
// line is a near-miss a sloppy token matcher would flag.

use CRM_Example_ExtensionUtil as E;

function civikitchen_fixture_clean($arr, $obj) {
  $a = civicrm_api4('Contact', 'get', ['checkPermissions' => FALSE]);
  $b = $arr['key'] ?? 'default';
  $c = new \PEAR_Error('constructed, not raised');
  $d = E::ts('Translated in the extension domain');
  $e = \Some\Util::ts('a static ts() on another class is not the global one');
  $f = $obj->ts('a method named ts is fine');
  $g = $obj->value('a method named value is fine');
  $h = $obj->civicrm_api3 ?? NULL;
  return [$a, $b, $c, $d, $e, $f, $g, $h];
}

/**
 * A method NAMED ts/value must not be flagged as a call.
 */
class CiviKitchenFixtureClean {

  public function ts(string $text): string {
    return $text;
  }

  public function value(string $key): string {
    return $key;
  }

}
