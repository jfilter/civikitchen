<?php

civicrm_api4('Contact', 'merge', ['checkPermissions' => FALSE]);

?>
-----
<?php

\Civi\Api4\Contact::merge(FALSE)->execute();

?>
