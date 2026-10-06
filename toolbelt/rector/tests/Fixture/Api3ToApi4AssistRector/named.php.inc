<?php

civicrm_api3(params: ['id' => 3], entity: 'Contact', action: 'get');

?>
-----
<?php

civicrm_api4('Contact', 'get', ['where' => [['id', '=', 3]], 'limit' => 25, 'checkPermissions' => false]);

?>
