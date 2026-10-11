<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Api4Names {
    use function civicrm_api4 as api4;

    function names(): void
    {
        api4('Contact', 'gett');
        \civicrm_api4('Contact', 'gett');
    }
}

namespace CiviKitchen\Fixtures\Api4Shadow {
    function civicrm_api4(string $entity, string $action): void
    {
    }

    /** The namespace's own function, not core's. */
    function shadowed(): void
    {
        civicrm_api4('Contact', 'gett');
    }
}
