<?php

declare(strict_types = 1);

require_once 'ckmonobase.civix.php';

function ckmonobase_civicrm_config(\CRM_Core_Config $config): void {
  _ckmonobase_civix_civicrm_config($config);
}

function ckmonobase_civicrm_install(): void {
  _ckmonobase_civix_civicrm_install();
}

function ckmonobase_civicrm_enable(): void {
  _ckmonobase_civix_civicrm_enable();
}
