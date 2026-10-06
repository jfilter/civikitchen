<?php

\CRM_Core_Error::fatal(code: 3, message: 'Boom');

?>
-----
<?php

throw new \CRM_Core_Exception('Boom', 3);

?>
