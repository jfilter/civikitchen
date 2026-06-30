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

// When standaloneusers is installed *during* `cv core:install`, its init plugin
// (civicrm-core's setup/plugins/init/StandaloneUsers.civi-setup.php) seeds an
// `admin` user — but with a random password the user can't know. That seeding is
// NOT reliable across versions, though: on some (e.g. standalone-6.12) cv
// core:install leaves standaloneusers uninstalled, so no admin user exists until
// the entrypoint enables it (ck_standalone_auth in images/lib/provision.sh).
// Handle both paths: if a user with this name already exists — the seeded admin,
// or a leftover from a docker-compose down/up where the DB volume persists but
// the settings file is gone — reset its password to the known DEMO_PASS instead
// of creating a duplicate; otherwise create it below.
$existing = \Civi\Api4\User::get(FALSE)
  ->addWhere('username', '=', $demoUser)
  ->execute()
  ->first();

if ($existing) {
  // Don't only reset the password: also (re)assert the admin role + active flag.
  // An existing row may be the standaloneusers init-plugin's seeded admin OR a
  // leftover from a down/up where the DB volume persisted — and on some versions
  // it can lack the admin role, which would leave the "admin" demo user unable to
  // administer anything. `roles:name` resolves the role name -> its civicrm_role
  // id (the raw `roles` field takes ids and would insert role_id 0 -> FK error).
  \Civi\Api4\User::update(FALSE)
    ->addWhere('id', '=', $existing['id'])
    ->addValue('password', $demoPass)
    ->addValue('is_active', TRUE)
    ->addValue('roles:name', ['admin'])
    ->execute();
  echo "[demo-user] Reset password + ensured admin role for existing user '{$demoUser}' (id={$existing['id']}).\n";
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
