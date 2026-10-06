<?php

\CRM_Core_Error::fatal('Boom');
\CRM_Core_Error::fatal('Boom', 'boom_code');

?>
-----
<?php

throw new \CRM_Core_Exception('Boom');
throw new \CRM_Core_Exception('Boom', 'boom_code');

?>
