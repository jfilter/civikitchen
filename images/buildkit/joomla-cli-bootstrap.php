<?php
// CLI bootstrap shim for the Joomla flavor (cv, civibuild, phpunit, …).
// Installed at /etc/civicrm.settings.d/post.d/ — civibuild's site settings load
// every *.php there in the 'post' phase, i.e. after the CiviCRM class loader is
// registered and $civicrm_root is set, but before any Civi container boot.
//
// Why it's needed: CiviCRM's Joomla integration (CRM_Utils_System_Joomla)
// reaches for JVERSION and \Joomla\CMS\Factory::getApplication() in methods that
// run during a normal container boot — factoryClassName(), getLoggedInUfID(),
// session setup. In a web request Joomla's index.php loads its framework
// (defining JVERSION and an application) before CiviCRM boots; under cv/CLI
// nothing does. So the first CRM_Core_Config::singleton() fatals with "Undefined
// constant JVERSION" *before* cv ever reaches CRM_Utils_System::loadBootStrap()
// — and because that fatal aborts the install-tracker mid-flight, it leaves the
// civicrm_install_canary table behind, which then poisons every later cv call
// ("Found installation canary"). That is why cv/phpunit don't work on a Joomla
// civibuild site out of the box.
//
// Fix: pull Joomla's framework load forward, here, using CiviCRM's own
// loadJoomlaFramework() (no fork). It defines JVERSION and, on Joomla 4+ under
// CLI, builds a real ConsoleApplication as the global app, so
// Factory::getApplication() works too (needed to create Joomla users from cv).
// With the bootstrap completing cleanly, the install tracker finishes and drops
// its own canary, so cv stops poisoning itself.
//
// Guards keep this inert everywhere it shouldn't fire:
//   - PHP_SAPI 'cli' only      → never touches the served web request
//   - CIVICRM_UF === 'Joomla'  → no-op on Drupal/WordPress/Standalone sites
//   - !defined('JVERSION')     → idempotent; a real Joomla bootstrap wins
if (PHP_SAPI === 'cli' && defined('CIVICRM_UF') && CIVICRM_UF === 'Joomla' && !defined('JVERSION')) {
  try {
    (new CRM_Utils_System_Joomla())->loadJoomlaFramework();

    // Joomla's framework.php registers TYPO3's PharStreamWrapper as a security
    // interceptor over phar:// access. That breaks cv, which runs *as* a phar:
    // every read of its own internals (phar:///.../bin/cv/...) then throws
    // "Unexpected file extension". cv (and civibuild/phpunit) are trusted here,
    // so restore PHP's native phar wrapper for this CLI process. The interceptor
    // only guards against untrusted phar deserialization in web requests, which
    // this CLI-only shim never runs in.
    if (class_exists(\TYPO3\PharStreamWrapper\PharStreamWrapper::class, FALSE)) {
      stream_wrapper_restore('phar');
    }
  }
  catch (\Throwable $e) {
    // Don't let a bootstrap hiccup hard-fail the settings include; cv will
    // surface the underlying CiviCRM error on its own if Joomla truly can't load.
    fwrite(STDERR, "[civikitchen] Joomla CLI bootstrap shim failed: {$e->getMessage()}\n");
  }
}
