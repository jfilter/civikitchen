<?php
// Core keeps `never` records and their managed rows on uninstall; it deletes `always` ones.
return [
  [
    'name' => 'OptionGroup_ckmanagedkept_kinds',
    'entity' => 'OptionGroup',
    'cleanup' => 'never',
    'params' => [
      'version' => 4,
      'values' => ['name' => 'ckmanagedkept_kinds', 'title' => 'ckmanagedkept kinds'],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionValue_activity_type_ckmanagedkept_note',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'ckmanagedkept_note',
        'label' => 'ckmanagedkept note',
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
  [
    'name' => 'OptionValue_activity_type_ckmanagedkept_gone',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'ckmanagedkept_gone',
        'label' => 'ckmanagedkept gone',
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
];
