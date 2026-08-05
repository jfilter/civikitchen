<?php
/**
 * Configure API users from profile.json (apiUsers + authx sections).
 *
 * Runs via `cv scr` from apply.sh. cv boots CiviCRM *and* the host CMS, so
 * one script covers all three user frameworks natively — no drush, no wp-cli:
 *   Drupal8    → Drupal entity API (\Drupal\user\Entity\User / Role)
 *   WordPress  → wp_insert_user() / add_role() / WP_Role::add_cap()
 *   Standalone → standaloneusers User/Role APIv4 entities
 *
 * Input: path to profile.json via the CK_PROFILE_JSON env var (`cv scr` has
 * no argv passthrough). Idempotent: load-or-create everywhere, so a re-run
 * after an aborted first boot converges instead of duplicating. Unlike the
 * old bash version this fails loudly — an uncaught exception makes cv exit
 * non-zero, apply.sh aborts, and the boot test goes red.
 */

$configFile = getenv('CK_PROFILE_JSON');
if (!$configFile || !is_readable($configFile)) {
  throw new \RuntimeException('configure-api-users: CK_PROFILE_JSON not set or unreadable: ' . var_export($configFile, TRUE));
}
$config = json_decode(file_get_contents($configFile), TRUE, 512, JSON_THROW_ON_ERROR);
$apiUsers = $config['apiUsers'] ?? [];
if (!$apiUsers) {
  echo "  No apiUsers configured\n";
  return;
}

$uf = CRM_Core_Config::singleton()->userFramework;
if (!in_array($uf, ['Drupal8', 'WordPress', 'Standalone', 'Joomla'], TRUE)) {
  throw new \RuntimeException("configure-api-users: unsupported user framework '{$uf}'");
}
echo "  🔑 Configuring API access ({$uf})...\n";

/**
 * CiviCRM permission → WordPress capability — the same mapping core applies
 * in CRM_Core_Permission_WordPress::check(): munge(strtolower($perm)).
 */
function ck_wp_cap(string $perm): string {
  return CRM_Utils_String::munge(strtolower($perm));
}

/**
 * Grant permissions to a Drupal role (created on demand). Unknown permission
 * names warn instead of throwing — Drupal 10 validates them on save, and one
 * typo in a profile must not torpedo the whole apply.
 */
function ck_drupal_grant(string $roleId, array $perms): void {
  $role = \Drupal\user\Entity\Role::load($roleId)
    ?: \Drupal\user\Entity\Role::create(['id' => $roleId, 'label' => $roleId]);
  $known = array_keys(\Drupal::service('user.permissions')->getPermissions());
  foreach ($perms as $perm) {
    if (in_array($perm, $known, TRUE)) {
      $role->grantPermission($perm);
    }
    else {
      echo "     WARN: unknown Drupal permission '{$perm}' (skipped)\n";
    }
  }
  $role->save();
}

// === AuthX: allow jwt/api_key/pass credentials in the Authorization header ===
echo "     Configuring AuthX...\n";
\Civi::settings()->set('authx_header_cred', $config['authx']['header_cred'] ?? ['jwt', 'api_key', 'pass']);
// Make CiviCRM's permission list known to the CMS before granting any.
civicrm_api3('System', 'flush');

// === Per-CMS prep: authx perm for built-in roles, known admin/demo passwords ===
switch ($uf) {
  case 'Drupal8':
    // authx's perm guard requires one permission per credential type.
    ck_drupal_grant('authenticated', ['authenticate with password', 'authenticate with api key']);
    ck_drupal_grant('administrator', ['authenticate with password', 'authenticate with api key', 'access CiviCRM', 'administer CiviCRM']);
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    foreach (['admin' => 'admin', 'demo' => 'demo'] as $name => $pass) {
      if ($accounts = $storage->loadByProperties(['name' => $name])) {
        $account = reset($accounts);
        $account->setPassword($pass);
        $account->save();
      }
    }
    break;

  case 'WordPress':
    if ($adminRole = get_role('administrator')) {
      $adminRole->add_cap('authenticate_with_password');
      $adminRole->add_cap('authenticate_with_api_key');
    }
    foreach (['admin' => 'admin', 'demo' => 'demo'] as $name => $pass) {
      if ($wpUser = get_user_by('login', $name)) {
        wp_set_password($pass, $wpUser->ID);
      }
    }
    break;

  case 'Standalone':
    // Built-in admin role already carries every permission; the per-user
    // roles below get "authenticate with password" added explicitly.
    break;

  case 'Joomla':
    // Reset the demo admin/demo logins to their documented passwords via
    // Joomla's user API (civibuild may set its own).
    $jUserFactory = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class);
    foreach (['admin' => 'admin', 'demo' => 'demo'] as $name => $pass) {
      $jid = (int) \Joomla\CMS\User\UserHelper::getUserId($name);
      if ($jid) {
        $acct = $jUserFactory->loadUserById($jid);
        // 'password2' mirrors 'password' so Joomla's User::bind() password-match
        // path doesn't warn on the missing confirmation field.
        $acctData = ['password' => $pass, 'password2' => $pass];
        $acct->bind($acctData);
        $acct->save();
      }
    }
    // CRM_Core_Permission_Joomla checks permissions via $user->authorise(perm,
    // 'com_civicrm'), which needs a com_civicrm ACL asset. civibuild never
    // registers the component (its install script can't run on the layout), so
    // that asset is absent — create it the way Joomla's installer would, once,
    // so the per-user grants below have something to attach to. Idempotent.
    $jdb = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
    $civiAsset = new \Joomla\CMS\Table\Asset($jdb);
    if (!$civiAsset->loadByName('com_civicrm')) {
      $civiAsset->name = 'com_civicrm';
      $civiAsset->title = 'CiviCRM';
      $civiAsset->setLocation((new \Joomla\CMS\Table\Asset($jdb))->getRootId(), 'last-child');
      $civiAsset->rules = '{}';
      if (!$civiAsset->check() || !$civiAsset->store()) {
        throw new \RuntimeException('configure-api-users: could not create com_civicrm ACL asset: ' . $civiAsset->getError());
      }
    }
    break;
}

// Keep the civibuild site config in sync with the reset passwords.
$siteSh = '/home/buildkit/buildkit/build/site.sh';
if (is_file($siteSh) && is_writable($siteSh)) {
  $content = file_get_contents($siteSh);
  $content = preg_replace('/^ADMIN_PASS=.*/m', 'ADMIN_PASS="admin"', $content);
  $content = preg_replace('/^DEMO_PASS=.*/m', 'DEMO_PASS="demo"', $content);
  file_put_contents($siteSh, $content);
}

// === Create the API users ===
echo "  👥 Creating API users...\n";

// Credentials are kept in the container so they stay retrievable after the
// log output scrolls away: docker exec <c> cat <credFile>. Default is the web
// user's $HOME/api-credentials.txt; CK_CREDENTIALS_FILE overrides the path
// (a public knob — external harnesses docker-cp the file out).
// The file is upserted per username, NOT truncated: with CIVIKITCHEN_PROFILE
// as a list this script runs once per profile, and truncating would drop the
// earlier profiles' users. A later profile reusing a username regenerates the
// api_key, so its line must replace the earlier one (whose key is gone from
// the DB).
$credFile = getenv('CK_CREDENTIALS_FILE') ?: ((getenv('HOME') ?: '/home/buildkit') . '/api-credentials.txt');
$credLines = [];
if (is_file($credFile)) {
  foreach (file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $credLines[explode(':', $line, 2)[0]] = $line;
  }
}
// file_put_contents only warns (and cv still exits 0) on an unwritable path,
// so a missing/read-only directory would go green with no creds file — check
// explicitly, per the fail-loud contract in the header.
if (file_put_contents($credFile, $credLines ? implode("\n", $credLines) . "\n" : '') === FALSE || !chmod($credFile, 0600)) {
  throw new \RuntimeException("configure-api-users: cannot create credentials file {$credFile}");
}

$credentials = [];
foreach ($apiUsers as $spec) {
  $username = $spec['username'];
  $roleName = $spec['role'];
  $perms = $spec['permissions'] ?? [];
  $password = $username;
  $email = "{$username}@example.org";
  echo "     Processing user: {$username} (role: {$roleName})\n";

  // CiviCRM contact (looked up by email so re-runs don't duplicate).
  $contactId = \Civi\Api4\Email::get(FALSE)
    ->addWhere('email', '=', $email)
    ->addSelect('contact_id')
    ->execute()->first()['contact_id'] ?? NULL;
  if (!$contactId) {
    $contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', ucfirst(strtolower($username)))
      ->addValue('last_name', 'User')
      ->execute()->first()['id'];
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $email)
      ->execute();
  }

  switch ($uf) {
    case 'Drupal8':
      ck_drupal_grant($roleName, $perms);
      $storage = \Drupal::entityTypeManager()->getStorage('user');
      $accounts = $storage->loadByProperties(['name' => $username]);
      $account = $accounts ? reset($accounts) : \Drupal\user\Entity\User::create(['name' => $username]);
      $account->setEmail($email);
      $account->setPassword($password);
      $account->activate();
      $account->addRole($roleName);
      $account->save();
      $uid = (int) $account->id();
      break;

    case 'WordPress':
      if (!get_role($roleName)) {
        add_role($roleName, $roleName);
      }
      $wpRole = get_role($roleName);
      // authx perm guards + the profile's permissions, as WP capabilities.
      $wpRole->add_cap('authenticate_with_password');
      $wpRole->add_cap('authenticate_with_api_key');
      foreach ($perms as $perm) {
        $wpRole->add_cap(ck_wp_cap($perm));
      }
      $uid = username_exists($username);
      if (!$uid) {
        $uid = wp_insert_user([
          'user_login' => $username,
          'user_email' => $email,
          'user_pass' => $password,
          'role' => $roleName,
        ]);
        if (is_wp_error($uid)) {
          throw new \RuntimeException("configure-api-users: wp_insert_user({$username}): " . $uid->get_error_message());
        }
      }
      else {
        wp_set_password($password, $uid);
        (new WP_User($uid))->set_role($roleName);
      }
      break;

    case 'Joomla':
      // Fine-grained Joomla ACL — the same least-privilege model as the other
      // CMSs, no Super User. Create a usergroup named after the role, grant it
      // exactly $perms (+ the authx guards) on the com_civicrm asset (ensured in
      // the prep step above), and put the user in that group. CiviCRM's
      // CRM_Core_Permission_Joomla maps each permission to a 'civicrm.<munged>'
      // action on that asset, so this grants precisely what the role needs.
      $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
      $grp = new \Joomla\CMS\Table\Usergroup($db);
      if (!$grp->load(['title' => $roleName])) {
        $grpData = ['title' => $roleName, 'parent_id' => 2];
        $grp->bind($grpData);
        if (!$grp->check() || !$grp->store()) {
          throw new \RuntimeException("configure-api-users: Joomla usergroup '{$roleName}': " . $grp->getError());
        }
      }
      $gid = (int) $grp->id;

      // Grant this role's permissions to the group on the com_civicrm asset.
      $permObj = new \CRM_Core_Permission_Joomla();
      $rules = json_decode($db->setQuery(
        $db->getQuery(TRUE)->select('rules')->from('#__assets')->where($db->quoteName('name') . ' = ' . $db->quote('com_civicrm'))
      )->loadResult() ?: '{}', TRUE);
      foreach (array_merge($perms, ['authenticate with password', 'authenticate with api key']) as $perm) {
        $action = $permObj->translateJoomlaPermission($perm);
        if (is_array($action)) {
          $rules[$action[0]][$gid] = 1;
        }
      }
      $db->setQuery(
        $db->getQuery(TRUE)->update('#__assets')->set($db->quoteName('rules') . ' = ' . $db->quote(json_encode($rules)))
          ->where($db->quoteName('name') . ' = ' . $db->quote('com_civicrm'))
      )->execute();

      // Create/load the user in Registered + the role group (NOT Super Users).
      $jUserFactory = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class);
      $uid = (int) \Joomla\CMS\User\UserHelper::getUserId($username);
      $jUser = $uid ? $jUserFactory->loadUserById($uid) : new \Joomla\CMS\User\User();
      $jData = [
        'name' => ucfirst(strtolower($username)) . ' User',
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'password2' => $password,
        'groups' => [2, $gid],
        'block' => 0,
      ];
      $jUser->bind($jData);
      if (!$jUser->save()) {
        throw new \RuntimeException("configure-api-users: Joomla user '{$username}': " . $jUser->getError());
      }
      $uid = (int) $jUser->id;
      break;

    case 'Standalone':
      // save+match keeps re-runs idempotent; "password" is a write-only field
      // hashed on save; "roles" wants role IDs. The User row IS the uf_match
      // record on Standalone, so the UFMatch step below is skipped.
      // Merge onto an existing role's permissions instead of replacing them:
      // combined profiles (CIVIKITCHEN_PROFILE list) may declare the same
      // role name with different permission sets, and the other CMS branches
      // (grantPermission / add_cap / ACL rules) accumulate too.
      $existingPerms = \Civi\Api4\Role::get(FALSE)
        ->addWhere('name', '=', $roleName)
        ->addSelect('permissions')
        ->execute()->first()['permissions'] ?? [];
      $roleId = \Civi\Api4\Role::save(FALSE)
        ->setRecords([
          [
            'name' => $roleName,
            'label' => $roleName,
            'permissions' => array_values(array_unique(array_merge($existingPerms, $perms, ['authenticate with password', 'authenticate with api key']))),
            'is_active' => TRUE,
          ],
        ])
        ->setMatch(['name'])
        ->execute()->first()['id'];
      $uid = \Civi\Api4\User::save(FALSE)
        ->setRecords([
          [
            'username' => $username,
            'uf_name' => $email,
            'contact_id' => $contactId,
            'password' => $password,
            'roles' => [$roleId],
            'is_active' => TRUE,
          ],
        ])
        ->setMatch(['username'])
        ->execute()->first()['id'];
      break;
  }

  // Link CMS user to CiviCRM contact so authx resolves the right contact.
  if ($uf !== 'Standalone') {
    \Civi\Api4\UFMatch::save(FALSE)
      ->setRecords([['uf_id' => $uid, 'uf_name' => $username, 'contact_id' => $contactId]])
      ->setMatch(['uf_id'])
      ->execute();
  }

  // API key: exactly 32 hex chars — civicrm_contact.api_key is varchar(32),
  // anything longer is silently mangled on save and authx rejects the key we
  // record here. The username mapping is in the credentials file anyway.
  $apiKey = bin2hex(random_bytes(16));
  \Civi\Api4\Contact::update(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addValue('api_key', $apiKey)
    ->execute();

  $credentials[] = [$username, $password, $apiKey];
  $credLines[$username] = "{$username}:{$password}:{$apiKey}";
}

if (file_put_contents($credFile, implode("\n", $credLines) . "\n") === FALSE) {
  throw new \RuntimeException("configure-api-users: cannot write credentials file {$credFile}");
}

echo "     ✓ API users configured successfully\n\n";
echo "==========================================\n";
echo "API User Credentials\n";
echo "==========================================\n\n";
foreach ($credentials as [$username, $password, $apiKey]) {
  printf("%-15s | Username: %-12s | Password: %-12s\n", 'User', $username, $password);
  printf("%-15s | API Key:  %s\n\n", '', $apiKey);
}
echo "==========================================\n\n";
echo "💡 Use these credentials for API testing:\n\n";
echo "   Example curl request (APIv4):\n";
echo '   curl -X POST "http://localhost/civicrm/ajax/api4/Contact/get" \\' . "\n";
echo '     -H "Authorization: Basic $(echo -n username:password | base64)" \\' . "\n";
echo '     -H "X-Requested-With: XMLHttpRequest" \\' . "\n";
echo '     --data-urlencode \'params={"limit":5}\'' . "\n\n";
echo "==========================================\n";

// Flush so the new roles/permissions take effect.
echo "     Flushing caches...\n";
switch ($uf) {
  case 'Drupal8':
    drupal_flush_all_caches();
    break;

  case 'WordPress':
    wp_cache_flush();
    break;
}
civicrm_api3('System', 'flush');

echo "     Credentials saved to {$credFile}\n";
