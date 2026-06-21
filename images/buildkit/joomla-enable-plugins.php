<?php
// Register CiviCRM's Joomla extensions — the com_civicrm component plus its
// system/user/quickicon plugins. Run via `cv scr` during the joomla-demo bake,
// after `php cli/joomla.php extension:discover` has registered the on-disk
// extensions into #__extensions as "discovered".
//
// civibuild's joomla-demo install is deliberately incomplete: its install.sh
// ends with a commented-out "#fixme joomla extension:install", so CiviCRM is
// never registered as a Joomla extension. Without that registration Joomla won't
// dispatch index.php?option=com_civicrm (the API route 404s) and CiviCRM gets no
// request hook. Completing the discover-install is exactly that missing #fixme
// step.
//
// The com_civicrm *component's* install script (script.civicrm.php) fails on
// civibuild's path layout — it require_once's admin/admin/configure.php, a path
// that only resolves in a standard package layout — but discover_install
// registers the component (which is what lets Joomla dispatch option=com_civicrm)
// before running that script, so the throw is caught and we carry on. The three
// plugins install cleanly.
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;

$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

// In #__extensions only CiviCRM's own rows match "civicrm" (com_civicrm + the
// civicrm/civicrmsys/civicrmicon plugins); CiviCRM's civi_* extensions live in
// CiviCRM's own registry, not Joomla's, so they are not matched here.
$eids = $db->setQuery(
  $db->getQuery(TRUE)
    ->select('extension_id')
    ->from('#__extensions')
    ->where($db->quoteName('element') . ' LIKE ' . $db->quote('%civicrm%'))
)->loadColumn();

if (!$eids) {
  fwrite(STDERR, "joomla-enable-plugins: no civicrm extensions discovered — did 'extension:discover' run?\n");
  return;
}

foreach ($eids as $eid) {
  try {
    (new Installer())->discover_install((int) $eid);
  }
  catch (\Throwable $e) {
    // Expected for com_civicrm (see above); the component is registered by now.
    fwrite(STDERR, "joomla-enable-plugins: discover_install({$eid}): {$e->getMessage()}\n");
  }
}

// Force enabled=1 AND state=0 (installed). discover_install can leave an
// extension published=0, and — crucially for the component — can roll its state
// back to -1 (discovered) when the install script errors; at state=-1 Joomla
// won't dispatch index.php?option=com_civicrm and the API route 404s. Setting
// state=0 makes Joomla treat the component as installed and route to it (via the
// legacy dispatcher), which is all the HTTP API needs.
$db->setQuery(
  $db->getQuery(TRUE)
    ->update('#__extensions')
    ->set([$db->quoteName('enabled') . ' = 1', $db->quoteName('state') . ' = 0'])
    ->where($db->quoteName('element') . ' LIKE ' . $db->quote('%civicrm%'))
)->execute();

echo 'joomla-enable-plugins: registered+enabled CiviCRM Joomla extensions [' . implode(',', $eids) . "]\n";
