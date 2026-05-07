<?php

// Create a CiviCRM Standalone login user (Contact + Email + User with admin
// role). Invoked from images/standalone/entrypoint.sh via `cv scr` after a
// successful `cv core:install`. Pattern lifted from civicrm-buildkit's
// app/config/standalone-clean/demo-user.php.

if (PHP_SAPI !== 'cli') {
  die("This script must be run from the command line.\n");
}

foreach (['DEMO_USER', 'DEMO_PASS', 'DEMO_EMAIL'] as $var) {
  if (getenv($var) === FALSE || getenv($var) === '') {
    fwrite(STDERR, "Missing env var: $var\n");
    exit(1);
  }
}

$demoUser = getenv('DEMO_USER');
$demoPass = getenv('DEMO_PASS');
$demoEmail = getenv('DEMO_EMAIL');

// `cv core:install` for Standalone already creates an `admin` user (see
// civicrm-core's setup/plugins/init/StandaloneUsers.civi-setup.php) — but
// with a random password the user can't know. So if a user with this name
// exists, reset its password to the known DEMO_PASS instead of creating
// a duplicate. Also covers the docker-compose down/up case where the db
// volume persists but the settings file is gone.
$existing = \Civi\Api4\User::get(FALSE)
  ->addWhere('username', '=', $demoUser)
  ->execute()
  ->first();

if ($existing) {
  \Civi\Api4\User::update(FALSE)
    ->addWhere('id', '=', $existing['id'])
    ->addValue('password', $demoPass)
    ->execute();
  echo "[demo-user] Reset password for existing user '{$demoUser}' (id={$existing['id']}).\n";
  return;
}

CRM_Core_Transaction::create()->run(function () use ($demoUser, $demoPass, $demoEmail) {
  $contactID = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Demo')
    ->addValue('last_name', 'User')
    ->execute()->single()['id'];

  \Civi\Api4\Email::create(FALSE)
    ->addValue('email', $demoEmail)
    ->addValue('contact_id', $contactID)
    ->execute();

  \Civi\Api4\User::create(FALSE)
    ->addValue('username', $demoUser)
    ->addValue('password', $demoPass)
    ->addValue('contact_id', $contactID)
    ->addValue('roles:name', ['admin'])
    ->execute();

  echo "[demo-user] Created user '{$demoUser}' (contact id={$contactID}).\n";
});
