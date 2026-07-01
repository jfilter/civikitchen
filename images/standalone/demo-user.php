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

// Idempotent upsert (no get-then-create/update split): mirrors the canonical
// provisioner images/profiles/configure-api-users.php so re-runs converge instead
// of duplicating. A user with this name may already exist — the standaloneusers
// init-plugin's seeded `admin` (random password the user can't know), or a
// leftover from a docker-compose down/up where the DB volume persisted but the
// settings file is gone — and on some versions (e.g. standalone-6.12) cv
// core:install leaves standaloneusers uninstalled entirely (then ck_standalone_auth
// in images/lib/provision.sh enables it first). In every case we converge the row
// to: known DEMO_PASS, active, admin role.

// CiviCRM contact, looked up by email so re-runs don't duplicate.
$contactId = \Civi\Api4\Email::get(FALSE)
  ->addWhere('email', '=', $demoEmail)
  ->addSelect('contact_id')
  ->execute()->first()['contact_id'] ?? NULL;
if (!$contactId) {
  $contactId = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Demo')
    ->addValue('last_name', 'User')
    ->execute()->single()['id'];
  \Civi\Api4\Email::create(FALSE)
    ->addValue('contact_id', $contactId)
    ->addValue('email', $demoEmail)
    ->execute();
}

// save + match on username = create-or-update in one call; every field is
// (re)asserted each run. `roles:name` resolves the role NAME to its civicrm_role
// id — the raw `roles` field takes ids and a name there inserts role_id 0 -> FK
// violation. `password` is write-only (hashed on save).
$userId = \Civi\Api4\User::save(FALSE)
  ->setRecords([[
    'username' => $demoUser,
    'uf_name' => $demoEmail,
    'contact_id' => $contactId,
    'password' => $demoPass,
    'roles:name' => ['admin'],
    'is_active' => TRUE,
  ]])
  ->setMatch(['username'])
  ->execute()->first()['id'];

echo "[demo-user] Ensured active admin user '{$demoUser}' (id={$userId}, contact id={$contactId}).\n";
