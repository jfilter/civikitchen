<?php

civicrm_api3('Contact', 'get', ['version' => 3, 'return' => 'id, contact_type']);

?>
-----
<?php

civicrm_api4('Contact', 'get', ['select' => ['id', 'contact_type'], 'limit' => 25, 'checkPermissions' => false]);

?>
