<?php

declare(strict_types = 1);

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
  // Namespaced functions named ts, a nullsafe method, and 'ts' as data, not
  // as a callback: none of them is core's ts('x').
  $i = \Some\Ns\ts('another function');
  $j = Ns\ts('relative');
  $k = namespace\ts('current namespace');
  $l = $obj?->ts('nullsafe');
  $m = array_map('ts' . 'x', $arr);
  $n = in_array('ts', $arr, TRUE);
  $o = $obj->array_map('ts', $arr);
  $p = \Some\Ns\array_map('ts', $arr);
  $q = array_filter('ts', $arr);
  $r = array_map(fn ($s) => E::ts($s), ['ts']);
  $s = "ts('in a string')";
  return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $p, $q, $r, $s];
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

  public function civikitchen_fixture_civicrm_managed(): array {
    return [];
  }

}
