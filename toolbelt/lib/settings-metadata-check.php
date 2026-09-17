<?php

/**
 * Post-(re)enable settings metadata check for cklifecycle. Run through `cv scr`
 * while the extension is installed; parameters arrive in the environment.
 *
 * A malformed 'pseudoconstant' on a setting (e.g. snake_case keys core does not
 * read) does not fail install or `cklint` — it fatals the first time CiviCRM
 * loads OPTIONS for settings, which happens for every setting on every visit
 * to /civicrm/admin/theme (it loads options for ALL settings) and on the
 * extension's own settings pages. ckconform's static settings-metadata check
 * catches the common shapes from source; this exercises the real call.
 */

// phpcs:disable Drupal.Commenting.InlineComment.DocBlock

$dir = getenv('CK_LC_DIR') ?: '';
if ($dir === '') {
  fwrite(STDERR, "settings-metadata-check: CK_LC_DIR must be set.\n");
  exit(2);
}

$names = [];
foreach (glob($dir . '/settings/*.setting.php') ?: [] as $file) {
  $settings = @include $file;
  if (!is_array($settings)) {
    continue;
  }
  foreach (array_keys($settings) as $name) {
    $names[] = (string) $name;
  }
}

if ($names === []) {
  echo "settings-metadata-check: no settings declared, nothing to check.\n";
  exit(0);
}

$findings = [];
foreach ($names as $name) {
  try {
    \Civi\Core\SettingsMetadata::getMetadata(['name' => [$name]], NULL, TRUE);
  }
  catch (\Throwable $e) {
    $findings[] = "{$name}: " . $e->getMessage();
  }
}

if ($findings === []) {
  echo "settings-metadata-check: options load cleanly for " . count($names) . " setting(s).\n";
  exit(0);
}
foreach ($findings as $finding) {
  echo "settings-metadata-check: FAILED: {$finding}\n";
}
exit(1);
