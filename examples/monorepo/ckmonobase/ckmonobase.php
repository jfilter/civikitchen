<?php

declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'ckmonobase.civix.php';
// phpcs:enable

function ckmonobase_civicrm_config(\CRM_Core_Config $config): void {
  _ckmonobase_civix_civicrm_config($config);
}

function ckmonobase_civicrm_install(): void {
  _ckmonobase_civix_civicrm_install();
}

function ckmonobase_civicrm_enable(): void {
  _ckmonobase_civix_civicrm_enable();
}
