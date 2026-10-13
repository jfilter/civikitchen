<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * civikitchen moved from jfilter to civico-de; the jfilter images and packages
 * stay pullable but no longer update. Prose is not read; comments are, since a
 * stale name there misleads the next reader as much.
 */
final class MovedRepositoryCheck implements Check
{
    /** Old name => new name, each matched as a whole name, case-insensitively like GitHub. */
    private const MOVED = [
        'jfilter/civikitchen' => 'civico-de/civikitchen',
        'jfilter/civikitchen-scenario-schema' => 'civico-de/civikitchen-scenario-schema',
        '@jfilter/civicrm-profile-schema' => '@civico-de/civicrm-profile-schema',
    ];

    private const PROSE = ['.md', '.markdown', '.rst', '.txt'];

    public function name(): string
    {
        return 'moved-repository';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $alternatives = implode('|', array_map(static fn (string $old): string => preg_quote($old, '/'), array_keys(self::MOVED)));
        $pattern = '/(?<![\w-])(' . $alternatives . ')(?![\w-])/i';
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
                    '%s:%d: %s moved to %s and no longer receives updates',
                    $file,
                    $number,
                    $match[1],
                    self::MOVED[strtolower($match[1])],
                ));
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
