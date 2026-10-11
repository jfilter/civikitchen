<?php

declare(strict_types=1);

// The CiviCRM call bans, read by phpstan (spaze/phpstan-disallowed-calls, via
// civicrm-disallowed.neon) and by the mago extension's disallowed-call hook.
return [
    'parameters' => [
        'disallowedFunctionCalls' => [
            ['function' => 'civicrm_api3()', 'message' => 'use civicrm_api4() / the APIv4 OO builders'],
            ['function' => 'civicrm_api()', 'message' => 'use civicrm_api4() / the APIv4 OO builders'],
        ],
        'disallowedStaticCalls' => [
            ['method' => 'CRM_Utils_Array::value()', 'message' => 'use the null-coalescing operator: $array[$key] ?? $default'],
            ['method' => 'CRM_Core_Error::fatal()', 'message' => 'throw an exception — fatal() is gone from core'],
            ['method' => 'CRM_Core_Error::debug_log_message()', 'message' => 'use \Civi::log()'],
            ['method' => 'CRM_Core_Error::debug_var()', 'message' => 'use \Civi::log() with a context array'],
            // Test doubles are the one place raising is the point being tested.
            [
                'method' => 'PEAR::raiseError()',
                'message' => 'construct new \PEAR_Error(...) — raiseError THROWS under the UnitTests UF',
                'allowIn' => ['tests/*'],
            ],
        ],
    ],
];
