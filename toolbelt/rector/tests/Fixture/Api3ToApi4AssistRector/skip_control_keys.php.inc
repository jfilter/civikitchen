<?php

civicrm_api3('Contact', 'get', ['rowCount' => 100]);
civicrm_api3('Contact', 'get', ['option.limit' => 0]);
civicrm_api3('Contact', 'get', ['return.contact_type' => 1]);
civicrm_api3('Contact', 'get', ['sort' => 'id DESC']);
civicrm_api3('Contact', 'get', ['offset' => 5]);
civicrm_api3('Contact', 'get', ['return' => $fields]);
civicrm_api3('Contact', 'get', ['id' => $ids]);
