<?php

civicrm_api4(action: 'get', entity: 'Contact', params: ['limit' => 5]);
civicrm_api4('Contact', params: ['limit' => 5], action: 'get');

?>
-----
<?php

\Civi\Api4\Contact::get()->setLimit(5)->execute();
\Civi\Api4\Contact::get()->setLimit(5)->execute();

?>
