<?php

declare(strict_types=1);

// A documented @property needs no __get/__set when the class or an ancestor
// allows dynamic properties; without that attribute the finding stays.
return [[
    'name' => 'dynamic-properties: an ancestor with #[AllowDynamicProperties] drops missing-magic-method',
    'workspace' => 'mago-ext/tests/fixtures/dynamic-properties',
    'command' => 'analyze',
    'code' => 'missing-magic-method',
    'paths' => ['classes.php'],
    'expect' => [
        'classes.php:18 Access to documented magic property `$params` on a class that cannot handle it.',
        'classes.php:22 Access to documented magic property `$params` on a class that cannot handle it.',
    ],
]];
