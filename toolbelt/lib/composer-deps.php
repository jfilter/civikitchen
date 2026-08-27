<?php

declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

/**
 * Default config for `ckdeps` (shipmonk/composer-dependency-analyser).
 *
 * CiviCRM is not a composer dependency of an extension — core is on the
 * classloader at runtime — so `CRM_*`, `Civi\*` and `civicrm_api4()` are
 * "unknown" to a composer-only view and would drown every real finding. They
 * are ignored by pattern; what remains is the part composer CAN answer:
 * a used-but-undeclared package or ext-*, a declared-but-unused one, and a
 * dev dependency reached from production code.
 *
 * A repo with more to say ships its own composer-dependency-analyser.php.
 */
$configuration = (new Configuration())
  ->ignoreUnknownClassesRegex('~^(CRM_|Civi(?:\\\\|$)|CiviCRM|CiviMix\\\\|GuzzleHttp\\\\|Psr\\\\)~')
  ->ignoreUnknownFunctionsRegex('~^(civicrm_|civi|CiviMix\\\\)~')
  // Paths are validated against the CWD, so a shared config cannot name
  // directories (node_modules) that only some repos have.
  ->disableReportingUnmatchedIgnores();

// A CiviCRM extension commonly maps the legacy PSR-0 `CRM_` prefix to `.`.
// ComposerDependencyAnalyser expands that autoload root recursively; after
// `composer install` this otherwise classifies vendor/ (including PHPUnit) as
// the extension's production code and reports every dev package as used in
// production. The directory is optional on a dependency-free checkout, and
// Configuration validates exclusions eagerly, hence the existence guard.
if (is_dir('vendor')) {
  $configuration->addPathToExclude('vendor');
}
if (is_dir('tests')) {
  $configuration->addPathToScan('tests', true);
}
// The template-managed phpstan bootstrap sits in the repo root (phpstan's
// bootstrapFiles path) but only ever runs under phpstan: what it uses
// (simplexml, the required extensions' autoloaders) is a dev dependency.
if (is_file('phpstanBootstrap.php')) {
  $configuration->addPathToScan('phpstanBootstrap.php', true);
}

return $configuration;
