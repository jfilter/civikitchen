<?php

declare(strict_types=1);

// @ck-legacy on a function, method or class-like exempts its body from the
// deprecation findings; everything else stays reported.
$class = 'Class `OldGreeter` is deprecated and should no longer be used.';
$method = 'Call to deprecated method: `OldGreeter::shout`.';
$function = 'Call to deprecated function: `old_greet`.';

return [[
    'name' => 'ck-legacy: marked scopes drop deprecation findings, unmarked ones keep them',
    'workspace' => 'mago-ext/tests/fixtures/ck-legacy',
    'command' => 'analyze',
    'code' => ['deprecated-class', 'deprecated-closure', 'deprecated-constant', 'deprecated-function', 'deprecated-method', 'deprecated-trait'],
    'paths' => ['callers.php'],
    'includes' => ['deprecated.php'],
    'expect' => [
        "callers.php:9 {$class}",
        "callers.php:9 {$method}",
        "callers.php:9 {$function}",
        "callers.php:34 {$function}",
        "callers.php:47 {$function}",
        "callers.php:59 {$function}",
        "callers.php:79 {$function}",
        "callers.php:90 {$function}",
    ],
]];
