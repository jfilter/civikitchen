<?php

declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'ckmonoaddon.civix.php';
// phpcs:enable

function ckmonoaddon_civicrm_config(\CRM_Core_Config $config): void {
  _ckmonoaddon_civix_civicrm_config($config);
}

function ckmonoaddon_civicrm_install(): void {
  _ckmonoaddon_civix_civicrm_install();
}

function ckmonoaddon_civicrm_enable(): void {
  _ckmonoaddon_civix_civicrm_enable();
}
