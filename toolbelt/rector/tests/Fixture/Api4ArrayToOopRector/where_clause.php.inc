<?php

civicrm_api4('Contact', 'get', ['where' => [['OR', [['id', '=', 1], ['id', '=', 2]]], ['id', 'IS NOT NULL']]]);

?>
-----
<?php

\Civi\Api4\Contact::get()->addClause('OR', [['id', '=', 1], ['id', '=', 2]])->addWhere('id', 'IS NOT NULL')->execute();

?>
