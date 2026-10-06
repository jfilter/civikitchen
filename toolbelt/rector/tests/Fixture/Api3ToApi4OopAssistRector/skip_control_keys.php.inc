<?php

civicrm_api3('Contact', 'get', ['rowCount' => 100]);
civicrm_api3('Contact', 'get', ['option.limit' => 0]);
civicrm_api3('Contact', 'get', ['return.contact_type' => 1]);
civicrm_api3('Contact', 'get', ['sort' => 'id DESC', 'offset' => 5]);
civicrm_api3('Contact', 'get', ['return' => $fields]);
