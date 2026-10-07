<?php

/**
 * Implements hook_civicrm_postInstall(): a value the extension adds to its
 * kept group by hand, so outside civicrm_managed.
 */
function ckmanagedkept_civicrm_postInstall(): void {
  civicrm_api4('OptionValue', 'save', [
    'checkPermissions' => FALSE,
    'records' => [['option_group_id.name' => 'ckmanagedkept_kinds', 'name' => 'ckmanagedkept_plain', 'label' => 'ckmanagedkept plain']],
    'match' => ['option_group_id', 'name'],
  ]);
}
