<?php

declare(strict_types=1);

$message = "Read the client address with CRM_Utils_System::ipAddress(), not \$_SERVER['REMOTE_ADDR'].";
$fixtures = 'mago-ext/tests/fixtures/rules/';

return [[
    'name' => 'remote-address: reads of $_SERVER[\'REMOTE_ADDR\'], writes and other keys silent',
    'command' => 'lint',
    'code' => 'civikitchen/remote-address',
    'paths' => [$fixtures . 'RemoteAddress.php', $fixtures . 'CleanModern.php'],
    'expect' => array_map(static fn (int $line): string => "{$fixtures}RemoteAddress.php:{$line} {$message}", [...range(8, 11), 32]),
]];
