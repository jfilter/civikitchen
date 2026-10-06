<?php

civicrm_api4('Contact', 'get', ['where' => [['id', '>', 1]], 'select' => ['id'], 'limit' => 5]);

?>
-----
<?php

\Civi\Api4\Contact::get()->addWhere('id', '>', 1)->addSelect('id')->setLimit(5)->execute();

?>
