<?php

function values(array $a, ?array $n, \ArrayAccess $o): void {
  $r1 = \CRM_Utils_Array::value('k', $a);
  $r2 = \CRM_Utils_Array::value('k', $n, NULL);
  $r3 = \CRM_Utils_Array::value('k', $o);
  $r4 = 'x' . \CRM_Utils_Array::value('k', $a);
  $r5 = \CRM_Utils_Array::value(list: $a, key: 'k');
}

?>
-----
<?php

function values(array $a, ?array $n, \ArrayAccess $o): void {
  $r1 = $a['k'] ?? null;
  $r2 = $n['k'] ?? null;
  $r3 = $o['k'] ?? null;
  $r4 = 'x' . ($a['k'] ?? null);
  $r5 = $a['k'] ?? null;
}

?>
