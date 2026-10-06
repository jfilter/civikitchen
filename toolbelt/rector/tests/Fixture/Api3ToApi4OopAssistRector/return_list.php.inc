<?php

civicrm_api3('Contact', 'get', ['version' => 3, 'return' => 'id,contact_type']);

?>
-----
<?php

\Civi\Api4\Contact::get(false)->addSelect('id', 'contact_type')->setLimit(25)->execute();

?>
