<?php

/**
 * CiviKitchen Joomla Identity Sync.
 *
 * CiviCRM-on-Joomla resolves permissions via $user->authorise(perm,
 * 'com_civicrm'), where $user is Joomla's *current identity*. In a headless
 * context — cv --user, or an authx api_key/jwt request — authx establishes the
 * CiviCRM logged-in contact but nothing sets Joomla's identity, so it stays the
 * guest user. Read paths happen to work (APIv4 applies contact-level ACLs via
 * the CiviCRM contact), but anything that calls CRM_Core_Permission::check() —
 * write actions, permission-checked seeds — is denied because Joomla evaluates
 * the guest.
 *
 * Fix: lazily, the first time a permission is checked while a CiviCRM contact is
 * logged in, load that contact's Joomla user as the application identity. This
 * runs on hook_civicrm_permission_check, which fires *inside*
 * CRM_Core_Permission::check() right before the Joomla authorise() — so the very
 * check that triggered it already sees the right user. It covers every headless
 * path (cv --user, authx api_key) with one mechanism, and is idempotent + a
 * no-op on a normal web request (identity already matches) and on other CMSs.
 */

function ckjoomlaidentity_civicrm_config(&$config) {
  if (!defined('CIVICRM_UF') || CIVICRM_UF !== 'Joomla') {
    return;
  }
  // High priority so the identity is in place before any later listener (or the
  // core check) evaluates the permission.
  \Civi::dispatcher()->addListener('hook_civicrm_permission_check', 'ckjoomlaidentity_sync_joomla_identity', 1000);
}

/**
 * Make Joomla's current identity match the logged-in CiviCRM contact.
 */
function ckjoomlaidentity_sync_joomla_identity($e) {
  if (!class_exists('\Joomla\CMS\Factory', FALSE)) {
    return;
  }
  $contactId = \CRM_Core_Session::getLoggedInContactID();
  if (!$contactId) {
    return;
  }
  $ufId = \CRM_Core_BAO_UFMatch::getUFId($contactId);
  if (!$ufId) {
    return;
  }
  $app = \Joomla\CMS\Factory::getApplication();
  $identity = $app->getIdentity();
  if ($identity && (int) $identity->id === (int) $ufId) {
    // Already the right user — nothing to do (keeps this cheap on repeat checks).
    return;
  }
  try {
    $user = \Joomla\CMS\Factory::getContainer()
      ->get(\Joomla\CMS\User\UserFactoryInterface::class)
      ->loadUserById((int) $ufId);
    $app->loadIdentity($user);
  }
  catch (\Throwable $ex) {
    // Best-effort: leave the identity as-is and let CiviCRM surface its own
    // permission error rather than this one.
  }
}
