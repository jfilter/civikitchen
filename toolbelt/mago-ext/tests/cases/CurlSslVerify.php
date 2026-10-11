<?php

declare(strict_types=1);

$message = 'Do not turn off TLS certificate verification: anyone on the path can then pose as the server.';
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'curl-ssl-verify: VERIFYPEER/VERIFYHOST turned off by literal, named or array options; on or dynamic values silent',
    'command' => 'lint',
    'code' => 'civikitchen/curl-ssl-verify',
    'paths' => [$fixtures . 'CurlSslVerify.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(static fn (int $line): string => "{$fixtures}CurlSslVerify.php:{$line} {$message}", [...range(8, 13), 24, 25, 30]),
]];
