<?php

declare(strict_types=1);

namespace Acme\Shadow;

// A namespaced function of the banned name wins over the global one.
function civicrm_api(string $entity, string $action): array
{
    return [];
}

civicrm_api('Contact', 'get');
\civicrm_api('Contact', 'get');
