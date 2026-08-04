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
return (new Configuration())
  ->ignoreUnknownClassesRegex('~^(CRM_|Civi\\\\|CiviCRM)~')
  ->ignoreUnknownFunctionsRegex('~^(civicrm_|civi)~')
  // Paths are validated against the CWD, so a shared config cannot name
  // directories (node_modules) that only some repos have.
  ->disableReportingUnmatchedIgnores();
