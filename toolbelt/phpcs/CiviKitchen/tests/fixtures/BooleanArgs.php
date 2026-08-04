<?php

declare(strict_types = 1);

// Fixture: positional boolean literals (flagged) against every near-miss a
// token matcher would trip on (not flagged). The test asserts exact lines.

function civikitchen_fixture_bools($arr, $obj, $flag) {
  $a = civikitchen_save($obj, TRUE);
  $b = civikitchen_save($obj, FALSE, 'label');
  $c = in_array('x', $arr, TRUE);
  $d = civikitchen_save($obj, checkPermissions: TRUE);
  $e = civikitchen_save($obj, $flag);
  $f = civikitchen_save($obj, $flag === TRUE);
  $g = TRUE;
  $h = [TRUE, FALSE];
  return [$a, $b, $c, $d, $e, $f, $g, $h];
}

function civikitchen_save($obj, bool $checkPermissions = TRUE, ?string $label = NULL) {
  return [$obj, $checkPermissions, $label];
}
