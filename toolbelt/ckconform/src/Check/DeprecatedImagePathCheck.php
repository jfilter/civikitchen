<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The image carries the toolbelt at /opt/civikitchen/toolbelt; the v1 paths
 * /opt/civikitchen-<tool> are links to it that v2 removes. A config, script or
 * workflow still naming one keeps working until then and breaks on the upgrade.
 *
 * Prose is not read: a changelog or README naming an old path is history, not
 * something that breaks. Only the first hit per file is reported.
 */
final class DeprecatedImagePathCheck implements Check
{
    /** Old path suffix => its place below /opt/civikitchen/toolbelt. */
    private const MOVED = [
        'phpstan-config' => 'phpstan-config',
        'phpstan-ext' => 'phpstan',
        'phpstan' => 'phpstan-root',
        'psalm' => 'psalm',
        'rector' => 'rector',
        'coder' => 'phpcs',
        'ckconform' => 'ckconform',
        'composer-deps.php' => 'lib/composer-deps.php',
        'oxlint' => 'oxlint',
        'oxfmt' => 'oxfmt',
        'mago' => 'mago',
    ];

    private const PROSE = ['.md', '.markdown', '.rst', '.txt'];

    public function name(): string
    {
        return 'deprecated-image-path';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $alternatives = implode('|', array_map(static fn (string $old): string => preg_quote($old, '/'), array_keys(self::MOVED)));
        $pattern = '/\/opt\/civikitchen-(' . $alternatives . ')(?![\w.-])/';
        $files = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        foreach (array_unique([...$files, ...$context->workflows()]) as $file) {
            if ($this->skipped($file)) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null || str_contains($contents, "\0") || !str_contains($contents, '/opt/civikitchen-')) {
                continue;
            }
            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match($pattern, $line, $match) !== 1) {
                    continue;
                }
                $number = $index + 1;
                $reporter->warnAt($file, $number, sprintf(
                    '%s:%d: /opt/civikitchen-%s is deprecated and goes away in civikitchen v2 — use /opt/civikitchen/toolbelt/%s',
                    $file,
                    $number,
                    $match[1],
                    self::MOVED[$match[1]],
                ));
                break;
            }
        }
    }

    private function skipped(string $file): bool
    {
        foreach (['vendor/', 'node_modules/'] as $directory) {
            if (str_starts_with($file, $directory) || str_contains($file, '/' . $directory)) {
                return true;
            }
        }
        foreach (self::PROSE as $extension) {
            if (str_ends_with(strtolower($file), $extension)) {
                return true;
            }
        }

        return false;
    }
}
