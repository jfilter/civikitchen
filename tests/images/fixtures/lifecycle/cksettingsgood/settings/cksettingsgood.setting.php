<?php
return [
  'cksettingsgood_group' => [
    'name' => 'cksettingsgood_group',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsgood_group',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['optionGroupName' => 'activity_type'],
  ],
  'cksettingsgood_table' => [
    'name' => 'cksettingsgood_table',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsgood_table',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['table' => 'civicrm_contact_type', 'keyColumn' => 'name', 'labelColumn' => 'label'],
  ],
  'cksettingsgood_callback' => [
    'name' => 'cksettingsgood_callback',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsgood_callback',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['callback' => 'CRM_Core_SelectValues::contactType'],
  ],
];
