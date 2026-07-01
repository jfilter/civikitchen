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

// Idempotent converge with an explicit update/create split (no save+match):
// civicrm_uf_match carries TWO unique indexes — (contact_id, domain_id) and
// (uf_name, domain_id) — so re-asserting contact_id/uf_name on an existing
// user can collide with ANOTHER User row (e.g. persistent DB volume + renamed
// DEMO_USER while DEMO_EMAIL still resolves to that other user's contact),
// throwing a DBQueryException that aborts the boot on every retry. A user
// with this name may already exist — the standaloneusers init-plugin's seeded
// `admin` (random password the user can't know), or a leftover from a
// docker-compose down/up where the DB volume persisted but the settings file
// is gone — and on some versions (e.g. standalone-6.12) cv core:install
// leaves standaloneusers uninstalled entirely (then ck_standalone_auth in
// images/lib/provision.sh enables it first).

$existing = \Civi\Api4\User::get(FALSE)
  ->addWhere('username', '=', $demoUser)
  ->addSelect('id', 'contact_id')
  ->execute()->first();

if ($existing) {
  // Converge ONLY the login fields to: known DEMO_PASS, active, admin role.
  // Leave contact_id/uf_name untouched — re-pointing them would silently
  // orphan the user's current contact and can hit the unique indexes above.
  // `roles:name` resolves the role NAME to its civicrm_role id — the raw
  // `roles` field takes ids and a name there inserts role_id 0 -> FK
  // violation. `password` is write-only (hashed on save).
  \Civi\Api4\User::update(FALSE)
    ->addWhere('id', '=', $existing['id'])
    ->addValue('password', $demoPass)
    ->addValue('roles:name', ['admin'])
    ->addValue('is_active', TRUE)
    ->execute();
  echo "[demo-user] Ensured active admin user '{$demoUser}' (id={$existing['id']}, contact id={$existing['contact_id']}).\n";
  return;
}

// No user with this name yet — create one, without tripping either unique
// index. uf_name (the user's login email) is unique per domain; if another
// user already claims $demoEmail there, give the new user a fresh contact
// WITHOUT that email and no uf_name — standaloneusers auto-fills uf_name from
// the contact's primary email (CRM_Standaloneusers_BAO_User::self_hook_civicrm_pre),
// so only an email-less contact reliably keeps it NULL. Login is by username,
// so the user works fine without one.
$ufNameTaken = (bool) \Civi\Api4\User::get(FALSE)
  ->addWhere('uf_name', '=', $demoEmail)
  ->execute()->first();
if ($ufNameTaken) {
  echo "[demo-user] Email {$demoEmail} is already another user's login email; creating '{$demoUser}' without one.\n";
}

// CiviCRM contact, looked up by email so re-runs don't duplicate — unless
// that contact is already linked to a DIFFERENT user, in which case linking
// it again would violate the (contact_id, domain_id) unique index: fall back
// to a fresh contact for the new user instead.
$contactId = NULL;
if (!$ufNameTaken) {
  $contactId = \Civi\Api4\Email::get(FALSE)
    ->addWhere('email', '=', $demoEmail)
    ->addSelect('contact_id')
    ->execute()->first()['contact_id'] ?? NULL;
  if ($contactId) {
    $linked = \Civi\Api4\User::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addSelect('username')
      ->execute()->first();
    if ($linked) {
      echo "[demo-user] Contact {$contactId} ({$demoEmail}) already belongs to user '{$linked['username']}'; creating a fresh contact.\n";
      $contactId = NULL;
    }
  }
}
if (!$contactId) {
  $contactId = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Demo')
    ->addValue('last_name', 'User')
    ->execute()->single()['id'];
  if (!$ufNameTaken) {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $demoEmail)
      ->execute();
  }
}

$create = \Civi\Api4\User::create(FALSE)
  ->addValue('username', $demoUser)
  ->addValue('contact_id', $contactId)
  ->addValue('password', $demoPass)
  ->addValue('roles:name', ['admin'])
  ->addValue('is_active', TRUE);
if (!$ufNameTaken) {
  $create->addValue('uf_name', $demoEmail);
}
$userId = $create->execute()->first()['id'];

echo "[demo-user] Ensured active admin user '{$demoUser}' (id={$userId}, contact id={$contactId}).\n";
