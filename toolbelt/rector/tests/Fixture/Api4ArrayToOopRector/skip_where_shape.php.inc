<?php

civicrm_api4('Contact', 'get', ['where' => [['or', [['id', '=', 1]]]]]);
civicrm_api4('Contact', 'get', ['where' => [[$field, $op, 1]]]);
civicrm_api4('Contact', 'get', ['where' => [['OR', ['id', '=', 1], ['id', '=', 2]]]]);
civicrm_api4('Activity', 'update', ['where' => [['modified_date', '<', 'created_date', TRUE]], 'values' => ['subject' => 'x']]);
civicrm_api4('Contact', 'get', ['where' => [['modified_date', '<', 'created_date', TRUE]]]);
civicrm_api4('Contact', 'get', ['where' => [['OR', ['id', '=', 1]]]]);
civicrm_api4('Contact', 'get', ['where' => [['OR', $clauses]]]);
civicrm_api4('Contact', 'get', ['where' => [['OR', ['a' => ['id', '=', 1], 'b' => ['id', '=', 2]]]]]);
