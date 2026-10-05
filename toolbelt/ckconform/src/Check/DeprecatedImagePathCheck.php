<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The image mirrors the checkout below /opt/civikitchen; the v1 paths
 * /opt/civikitchen-<tool> and /usr/local/share/civikitchen/profiles are links
 * into it that v2 removes. A config, script, workflow or compose mount still
 * naming one keeps working until then and breaks on the upgrade.
 *
 * Prose is not read: a changelog or README naming an old path is history, not
 * something that breaks. Only the first hit per file is reported.
 */
final class DeprecatedImagePathCheck implements Check
{
    /** v1 path => where it lives now. */
    private const MOVED = [
        '/opt/civikitchen-phpstan-config' => '/opt/civikitchen/toolbelt/phpstan-config',
        '/opt/civikitchen-phpstan-ext' => '/opt/civikitchen/toolbelt/phpstan',
        '/opt/civikitchen-phpstan' => '/opt/civikitchen/toolbelt/phpstan-root',
        '/opt/civikitchen-psalm' => '/opt/civikitchen/toolbelt/psalm',
        '/opt/civikitchen-rector' => '/opt/civikitchen/toolbelt/rector',
        '/opt/civikitchen-coder' => '/opt/civikitchen/toolbelt/phpcs',
        '/opt/civikitchen-ckconform' => '/opt/civikitchen/toolbelt/ckconform',
        '/opt/civikitchen-composer-deps.php' => '/opt/civikitchen/toolbelt/lib/composer-deps.php',
        '/opt/civikitchen-oxlint' => '/opt/civikitchen/toolbelt/oxlint',
        '/opt/civikitchen-oxfmt' => '/opt/civikitchen/toolbelt/oxfmt',
        '/opt/civikitchen-mago' => '/opt/civikitchen/toolbelt/mago',
        '/usr/local/share/civikitchen/profiles' => '/opt/civikitchen/docker/profiles',
    ];

    private const PROSE = ['.md', '.markdown', '.rst', '.txt'];

    public function name(): string
    {
        return 'deprecated-image-path';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $alternatives = implode('|', array_map(static fn (string $old): string => preg_quote($old, '/'), array_keys(self::MOVED)));
        $pattern = '/(' . $alternatives . ')(?![\w.-])/';
        $files = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        foreach (array_unique([...$files, ...$context->workflows()]) as $file) {
            if ($this->skipped($file)) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null || str_contains($contents, "\0") || preg_match($pattern, $contents) !== 1) {
                continue;
            }
            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match($pattern, $line, $match) !== 1) {
                    continue;
                }
                $number = $index + 1;
                $reporter->warnAt($file, $number, sprintf(
                    '%s:%d: %s is deprecated and goes away in civikitchen v2 — use %s',
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
