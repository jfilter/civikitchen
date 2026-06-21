<?php
// Link the Joomla CMS admin user to a CiviCRM contact (run via cv scr).
//
// civibuild's joomla-demo install is deliberately incomplete — its install.sh
// ends with a commented-out "#fixme joomla extension:install" and the note
// "everything below here is generally untested". One thing the proper install
// would do (and Drupal/WordPress/Standalone do at install time) is link the CMS
// admin to a CiviCRM contact. Without that UFMatch, `cv --user=admin` fails with
// "Cannot login. Failed to determine contact ID", which blocks running the
// profile seeds as admin. Create the link the same way CiviCRM itself does, via
// CRM_Core_BAO_UFMatch::synchronizeUFMatch (idempotent). Joomla only.
if (CIVICRM_UF !== 'Joomla') {
  return;
}

$uid = (int) \Joomla\CMS\User\UserHelper::getUserId('admin');
if (!$uid) {
  fwrite(STDERR, "joomla-link-admin: no Joomla 'admin' user found\n");
  return;
}
if (\Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $uid)->execute()->first()) {
  echo "joomla-link-admin: admin (uf_id={$uid}) already linked\n";
  return;
}

$jUser = \Joomla\CMS\Factory::getContainer()
  ->get(\Joomla\CMS\User\UserFactoryInterface::class)
  ->loadUserById($uid);
\CRM_Core_BAO_UFMatch::synchronizeUFMatch($jUser, $uid, $jUser->email, 'Joomla');
$cid = \Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $uid)->addSelect('contact_id')->execute()->first()['contact_id'] ?? '?';
echo "joomla-link-admin: linked admin (uf_id={$uid}) -> contact {$cid}\n";
