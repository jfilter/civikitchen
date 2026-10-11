<?php

// Fixture: calls of core's global ts() that CiviKitchen.I18n.UseExtensionTs
// must flag. Exact line numbers asserted by the test; the near misses of the
// issue table live in CleanModern.php.

function civikitchen_fixture_ts($labels, $rows) {
  $a = ts('Hello');
  $b = \ts('World, %1', [1 => 'x']);
  $c = ts /* reason */ ('commented');
  $d = ts(...);
  $e = TS('upper');
  $f = Ts('mixed');
  $g = array_map('ts', $labels);
  $h = call_user_func('ts', 'x');
  $i = call_user_func_array("TS", ['x']);
  $j = array_filter($labels, 'ts');
  $k = array_walk($rows, 'ts');
  $l = array_walk_recursive($rows, 'ts');
  $m = usort($rows, 'ts');
  $n = uasort($rows, 'ts');
  $o = uksort($rows, 'ts');
  $p = array_reduce($rows, 'ts');
  $q = array_map(callback: 'ts', array: $labels);
  $r = \array_map('ts', $labels);
  return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $p, $q, $r];
}
