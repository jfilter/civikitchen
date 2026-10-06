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
  $i = $obj->setUseTrash(FALSE);
  $j = $obj->setFlags('x', TRUE);
  $k = civikitchen_settle(TRUE);
  return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k];
}

function civikitchen_save($obj, bool $checkPermissions = TRUE, ?string $label = NULL) {
  return [$obj, $checkPermissions, $label];
}

function civikitchen_settle(bool $now) {
  return $now;
}

function civikitchen_fixture_bool_values($obj, $list, $x) {
  $a = $obj->run(TRUE);
  $b = new \ArrayObject(TRUE);
  $c = $obj->run(\TRUE);
  $d = IN_ARRAY($x, $list, TRUE);
  $e = array_push($list, TRUE);
  $f = $obj->addWhere('is_active', '=', TRUE);
  $g = $obj->addValue('is_active', TRUE);
  $h = \Civi::settings()->set('flag', TRUE);
  $i = $obj->assertSame(TRUE, $x);
  $j = $obj->ADDWHERE('is_active', '=', \FALSE);
  $k = \in_array($x, $list, \TRUE);
  $l = array_unshift($list, FALSE);
  $m = array_fill(0, 2, TRUE);
  $n = array_fill_keys($list, FALSE);
  $o = array_pad($list, 2, TRUE);
  $p = $obj->assertEquals(FALSE, $x);
  $q = $obj->assertNotSame(TRUE, $x);
  $r = $obj->assertNotEquals(FALSE, $x);
  $s = $obj->addHaving('total', '=', TRUE);
  return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $p, $q, $r, $s];
}
