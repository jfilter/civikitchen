<?php
return [
  // Core reads optionGroupName; a snake_case key leaves the options undefined.
  'cksettingsbad_snake' => [
    'name' => 'cksettingsbad_snake',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsbad_snake',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['option_group_name' => 'activity_type'],
  ],
  // A table pseudoconstant needs keyColumn and labelColumn.
  'cksettingsbad_table' => [
    'name' => 'cksettingsbad_table',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsbad_table',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['table' => 'civicrm_contact_type'],
  ],
  // The callback names a method that does not exist.
  'cksettingsbad_callback' => [
    'name' => 'cksettingsbad_callback',
    'type' => 'String',
    'default' => NULL,
    'html_type' => 'select',
    'title' => 'cksettingsbad_callback',
    'is_domain' => 1,
    'is_contact' => 0,
    'pseudoconstant' => ['callback' => 'CRM_Core_SelectValues::ckDoesNotExist'],
  ],
];
