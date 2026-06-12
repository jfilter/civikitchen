<?php

// Fixture: every default ban of CiviKitchen.Legacy.NoLegacyCall, one per
// line — the test asserts the exact line numbers below.

function civikitchen_fixture_legacy($arr) {
  $a = civicrm_api3('Contact', 'get', []);
  $b = civicrm_api('Contact', 'get', ['version' => 3]);
  $c = CRM_Utils_Array::value('key', $arr, 'default');
  $d = \PEAR::raiseError('boom');
  CRM_Core_Error::fatal('dead');
  CRM_Core_Error::debug_log_message('log me');
  CRM_Core_Error::debug_var('var', $arr);
  return [$a, $b, $c, $d];
}
