<?php

ini_set('memory_limit', '2G');

// phpcs:disable
eval(cv('php:boot --level=classloader', 'phpcode'));
// phpcs:enable

// CRITICAL GUARD: the headless suite must run against a SEPARATE scratch
// database. The DSN comes from cv's config (TEST_DB_DSN in ~/.cv.json —
// provisioned by the civikitchen entrypoint for BOTH /root and /var/www):
// the phpunit listener re-boots via `cv php:boot --level=full`, whose
// $GLOBALS['_CV'] replaces anything set here. If the config is missing,
// civicrm.settings.php silently falls back to the MAIN dev DB and
// Civi\Test wipes all dev data — so fail loudly instead.
$cvConfigHasTestDsn = FALSE;
foreach ([getenv('HOME') ?: '', '/root', '/var/www'] as $home) {
  $raw = $home ? (string) @file_get_contents($home . '/.cv.json') : '';
  // Site-keyed config: {"sites": {"<settings path>": {"TEST_DB_DSN": "...civicrm_test..."}}}
  if (str_contains($raw, '"TEST_DB_DSN"') && str_contains($raw, 'civicrm_test')) {
    $cvConfigHasTestDsn = TRUE;
    break;
  }
}
if (!$cvConfigHasTestDsn) {
  fwrite(STDERR, "ABORT: no TEST_DB_DSN in ~/.cv.json — headless tests would rebuild the MAIN dev DB.\n"
    . "Re-provision the stack (`docker compose down -v && up -d`) — the civikitchen\n"
    . "entrypoint writes TEST_DB_DSN and seeds the civicrm_test scratch DB on first boot.\n");
  exit(1);
}

// Standalone quirk: SettingsManager::bootSettings() composes CIVICRM_DSN
// from the CIVICRM_DB_* env vars BEFORE settings.php gets a chance to apply
// TEST_DB_DSN — the env-composed (main!) DSN would win. Repoint the DB name
// at the scratch DB for this whole test process (inherited by the
// listener's `cv php:boot --level=full` subprocess too).
putenv('CIVICRM_DB_NAME=civicrm_test');
$_ENV['CIVICRM_DB_NAME'] = 'civicrm_test';

// Allow autoloading of PHPUnit helper classes in this extension.
$loader = new \Composer\Autoload\ClassLoader();
$loader->add('CRM_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('Civi\\', [__DIR__ . '/../../Civi', __DIR__ . '/Civi']);
$loader->add('api_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('api\\', [__DIR__ . '/../../api', __DIR__ . '/api']);

$loader->register();

/**
 * Call the "cv" command.
 *
 * @param string $cmd
 *   The rest of the command to send.
 * @param string $decode
 *   Ex: 'json' or 'phpcode'.
 * @return mixed
 *   Response output (if the command executed normally).
 *   For 'raw' or 'phpcode', this will be a string. For 'json', it could be any JSON value.
 * @throws \RuntimeException
 *   If the command terminates abnormally.
 */
function cv(string $cmd, string $decode = 'json') {
  $cmd = 'cv ' . $cmd;
  $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
  $oldOutput = getenv('CV_OUTPUT');
  putenv('CV_OUTPUT=json');

  // Execute `cv` in the original folder. This is a work-around for
  // phpunit/codeception, which seem to manipulate PWD.
  $cmd = sprintf('cd %s; %s', escapeshellarg(getenv('PWD')), $cmd);

  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  putenv("CV_OUTPUT=$oldOutput");
  fclose($pipes[0]);
  $result = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException("Command failed ($cmd):\n$result");
  }
  switch ($decode) {
    case 'raw':
      return $result;

    case 'phpcode':
      // If the last output is /*PHPCODE*/, then we managed to complete execution.
      if (substr(trim($result), 0, 12) !== '/*BEGINPHP*/' || substr(trim($result), -10) !== '/*ENDPHP*/') {
        throw new \RuntimeException("Command failed ($cmd):\n$result");
      }
      return $result;

    case 'json':
      return json_decode($result, 1);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}
