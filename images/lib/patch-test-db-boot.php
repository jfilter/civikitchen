<?php
/**
 * Patch the standalone boot stub (civicrm.standalone.php) so that
 * CIVICRM_UF=UnitTests boots hit the isolated test database.
 *
 * Why this is necessary: CiviCRM core's SettingsManager::bootSettings()
 * composes CIVICRM_DSN from the CIVICRM_DB_* environment variables BEFORE
 * civicrm.settings.php is loaded ("env takes precedence over the settings
 * file"). In an env-configured container that means the settings file's own
 * "CIVICRM_UF=UnitTests → use $GLOBALS['_CV']['TEST_DB_DSN']" branch is dead
 * code — CIVICRM_DSN is already defined — and headless phpunit silently runs
 * against the dev database. The only reliable interception point is the boot
 * stub, which runs in-process before bootSettings().
 *
 * Usage:  php patch-test-db-boot.php [/path/to/civicrm.standalone.php]
 * Idempotent: skips when the [civikitchen-test-db] marker is present.
 * Never fatal: missing stub or anchor exits 0 (non-standalone layouts).
 */

$stub = $argv[1] ?? '/var/www/html/civicrm.standalone.php';

if (!is_file($stub)) {
  // Non-standalone layout (no stub) — nothing to do.
  exit(0);
}

$src = file_get_contents($stub);

if (strpos($src, 'civikitchen-test-db') !== FALSE) {
  // Already patched.
  exit(0);
}

$inject = <<<'PHP'
// [civikitchen-test-db] Route CIVICRM_UF=UnitTests boots at the isolated
// test database. Core's SettingsManager::bootSettings() composes CIVICRM_DSN
// from the CIVICRM_DB_* env vars BEFORE the settings file loads, so the
// settings file's UnitTests/TEST_DB_DSN branch can never fire here — without
// this block, headless tests silently run against the dev database.
// Rewrite the CIVICRM_DB_* env vars instead of defining CIVICRM_DSN
// directly: core then composes the DSN itself and its settings bag stays
// consistent with the constant. A stub-level define() leaves the bag on the
// main DB and the boot dies in a translation that runs before DAO::init().
if (getenv('CIVICRM_UF') === 'UnitTests' && !defined('CIVICRM_DSN')) {
  $ckTestDsn = $GLOBALS['_CV']['TEST_DB_DSN'] ?? NULL;
  $ckParts = $ckTestDsn ? parse_url($ckTestDsn) : NULL;
  if (!empty($ckParts['path'])) {
    putenv('CIVICRM_DB_NAME=' . ltrim($ckParts['path'], '/'));
    if (!empty($ckParts['host'])) { putenv('CIVICRM_DB_HOST=' . $ckParts['host']); }
    if (!empty($ckParts['port'])) { putenv('CIVICRM_DB_PORT=' . $ckParts['port']); }
    if (!empty($ckParts['user'])) { putenv('CIVICRM_DB_USER=' . $ckParts['user']); }
    if (!empty($ckParts['pass'])) { putenv('CIVICRM_DB_PASSWORD=' . $ckParts['pass']); }
  }
  elseif (getenv('CIVICRM_DB_NAME') && substr((string) getenv('CIVICRM_DB_NAME'), -5) !== '_test') {
    putenv('CIVICRM_DB_NAME=' . getenv('CIVICRM_DB_NAME') . '_test');
  }
  unset($ckTestDsn, $ckParts);
}

PHP;

$anchor = '\Civi\Core\SettingsManager::bootSettings';
$pos = strpos($src, $anchor);
if ($pos === FALSE) {
  fwrite(STDERR, "[civikitchen] WARN: bootSettings anchor not found in $stub; test-db boot patch skipped\n");
  exit(0);
}

$src = substr($src, 0, $pos) . $inject . substr($src, $pos);
file_put_contents($stub, $src);
echo "[civikitchen] Patched $stub: UnitTests boots now use the test DB.\n";
