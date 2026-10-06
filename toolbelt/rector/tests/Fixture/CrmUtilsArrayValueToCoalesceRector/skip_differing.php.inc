<?php

function values(array $a, \stdClass $s, $m, bool $c, $x, $b, array $rest, string|array $sa, string $str): void {
  $r1 = \CRM_Utils_Array::value('k', $a, $c ? 1 : 2);
  $r2 = \CRM_Utils_Array::value('k', $a, $x ?: 'y');
  $r3 = \CRM_Utils_Array::value('k', $a, 'd');
  $r4 = \CRM_Utils_Array::value('k', $s);
  $r5 = \CRM_Utils_Array::value('k', $m);
  $r6 = \CRM_Utils_Array::value('k', ...$rest);
  $r7 = \CRM_Utils_Array::value('k', $a, $b = 3);
  $r8 = \CRM_Utils_Array::value(key: 'k', array: $a);
  $r9 = \CRM_Utils_Array::value(0, $sa);
  $r10 = \CRM_Utils_Array::value(0, $str);
}
