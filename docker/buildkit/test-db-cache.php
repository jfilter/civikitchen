<?php
// civibuild pre.d shim: UnitTests boots use ArrayCache, i.e. civicrm_cache in the
// test DB, because version-scoped file/Redis caches are shared with the dev site.
$ckUf = defined('CIVICRM_UF') ? CIVICRM_UF : getenv('CIVICRM_UF');
if ($ckUf === 'UnitTests' && !defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'ArrayCache');
}
unset($ckUf);
