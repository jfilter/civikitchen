<?php
// Installed at /etc/civicrm.settings.d/pre.d/: civibuild's site settings load
// it before the settings template's guarded CIVICRM_DB_CACHE_CLASS define.
//
// CIVICRM_UF=UnitTests boots cache in the test database: ArrayCache falls back
// to SqlGroup, civicrm_cache in TEST_DB_DSN, which Civi\Test's schema rebuild
// drops. Core keys its version-scoped caches by cache name and CiviCRM version
// only, so a FileCache, Redis or Memcache cache would be shared with the dev
// site and outlive the rebuild.
$ckUf = defined('CIVICRM_UF') ? CIVICRM_UF : getenv('CIVICRM_UF');
if ($ckUf === 'UnitTests' && !defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'ArrayCache');
}
unset($ckUf);
