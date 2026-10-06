<?php

civicrm_api4('Custom_Survey', 'get', []);
civicrm_api4('MembershipPayment', 'get', []);
civicrm_api4('contact', 'get', []);
civicrm_api4('CustomValue', 'get', []);
civicrm_api4('Contact', 'merge', ['checkPermissions' => $perm]);
