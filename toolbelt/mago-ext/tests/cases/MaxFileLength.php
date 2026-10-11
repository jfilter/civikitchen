<?php

declare(strict_types=1);

// Port of CiviKitchen.Files.MaxFileLength: the phpcs SniffsTest expectations.
$fixtures = 'mago-ext/tests/fixtures/rules/';
$tooLong = static fn (string $file, int $lines): string => "{$fixtures}{$file}:1 File is {$lines} lines long; the maximum is 10. Split it into focused classes.";
$armed = ['LongFile.php' => 18, 'ShortPhp.php' => 11, 'TemplateEcho.php' => 15, 'TemplateHtml.php' => 15];

return [
    [
        'name' => 'max-file-length: the default cap leaves small files and modern counterparts alone',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'paths' => [$fixtures . 'LongFile.php', $fixtures . 'CleanModern.php'],
        'expect' => [],
    ],
    [
        'name' => 'max-file-length: a low cap flags every over-long file on line 1, whatever it opens with',
        'command' => 'lint',
        'code' => 'civikitchen/max-file-length',
        'args' => ['--max-lines=10'],
        'paths' => array_map(static fn (string $file): string => $fixtures . $file, array_keys($armed)),
        'expect' => array_map($tooLong, array_keys($armed), $armed),
    ],
];
