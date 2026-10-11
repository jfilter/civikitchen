<?php

declare(strict_types=1);

// The bans of phpstan-config/civicrm-disallowed.php: the findings phpstan's
// disallowed-calls extension gives on the same fixture, run from its directory,
// except shadowed.php:13, where PHP calls the namespaced function and phpstan errs.
$api = 'use civicrm_api4() / the APIv4 OO builders.';
$value = 'Calling CRM_Utils_Array::value() is forbidden, use the null-coalescing operator: $array[$key] ?? $default.';
$fatal = 'is forbidden, throw an exception — fatal() is gone from core.';
$common = [
    'workspace' => 'mago-ext/tests/fixtures/disallowed',
    'command' => 'analyze',
    'paths' => ['calls.php', 'shadowed.php', 'tests'],
    'includes' => ['stubs.php'],
];

return [
    $common + [
        'name' => 'disallowed-function: civicrm_api3/civicrm_api, also namespaced and fully qualified',
        'code' => 'civikitchen/disallowed-function',
        'expect' => [
            "calls.php:13 Calling civicrm_api3() is forbidden, {$api}",
            "calls.php:14 Calling civicrm_api() is forbidden, {$api}",
            "shadowed.php:14 Calling civicrm_api() is forbidden, {$api}",
        ],
    ],
    $common + [
        'name' => 'disallowed-method: static bans through alias, class-string and subclass; allowIn tests/*',
        'code' => 'civikitchen/disallowed-method',
        'expect' => [
            "calls.php:15 {$value}",
            "calls.php:16 {$value}",
            "calls.php:18 {$value}",
            "calls.php:19 Calling CRM_Core_Error::fatal() {$fatal}",
            'calls.php:20 Calling CRM_Core_Error::debug_log_message() is forbidden, use \Civi::log().',
            'calls.php:21 Calling CRM_Core_Error::debug_var() is forbidden, use \Civi::log() with a context array.',
            'calls.php:22 Calling PEAR::raiseError() is forbidden, construct new \PEAR_Error(...) — raiseError THROWS under the UnitTests UF.',
            "calls.php:23 Calling CRM_Core_Error::fatal() (as CRM_Core_Exception_Stub::fatal()) {$fatal}",
            "calls.php:43 Calling CRM_Core_Error::fatal() {$fatal}",
            'calls.php:44 Calling CRM_Core_Error::debug_var() (as Acme\\Widget\\LegacyError::debug_var()) is forbidden, use \\Civi::log() with a context array.',
            "calls.php:52 Calling CRM_Core_Error::fatal() (as CRM_Core_Exception_Stub::fatal()) {$fatal}",
            "tests/RaisesTest.php:10 {$value}",
        ],
    ],
];
