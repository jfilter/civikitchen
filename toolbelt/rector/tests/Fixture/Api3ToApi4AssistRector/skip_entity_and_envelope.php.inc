<?php

civicrm_api3('option_value', 'get', ['id' => 3]);
civicrm_api3('MembershipPayment', 'get', ['id' => 3]);
$values = civicrm_api3('Contact', 'get', ['id' => 3])['values'];
