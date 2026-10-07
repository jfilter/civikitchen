<?php
// An unclosed {if}: stripping Civi tokens must not hide a Smarty error.
return [
  [
    'name' => 'MessageTemplate_ckmsgtplbad',
    'entity' => 'MessageTemplate',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'ckmsgtplbad',
        'msg_subject' => 'Hello {contact.first_name}',
        'msg_html' => '<p>{contact.display_name}</p>{if $note}never closed',
      ],
      'match' => ['msg_title'],
    ],
  ],
];
