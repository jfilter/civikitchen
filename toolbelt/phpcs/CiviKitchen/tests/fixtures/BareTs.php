<?php

// Fixture: bare ts() calls that CiviKitchen.I18n.UseExtensionTs must flag —
// plain and fully qualified. Exact line numbers asserted by the test.

function civikitchen_fixture_ts() {
  $a = ts('Hello');
  $b = \ts('World, %1', [1 => 'x']);
  return [$a, $b];
}
