<?php

civicrm_api3(action: 'get', entity: 'Contact', params: ['id' => 3]);

?>
-----
<?php

\Civi\Api4\Contact::get(false)->addWhere('id', '=', 3)->setLimit(25)->execute();

?>
