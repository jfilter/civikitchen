<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * Evaluates the extension's `*.mgd.php` files for the managed-entity checks.
 *
 * mgd files are `return [...]` PHP with at most an ExtensionUtil dependency, so
 * they can be run with a stubbed ExtensionUtil; a file that throws anyway is
 * reported as a warning rather than silently skipped — $subject names what went
 * unchecked. Tracked files only (repo principle); mgd files live under managed/
 * by convention but nothing enforces that, so the whole tree is scanned.
 */
final class ManagedFiles
{
    /**
     * The evaluated records of every mgd file, as [file, records] pairs.
     *
     * A file that does not return an array yields nothing; a check that wants
     * to report that case passes $onNotArray, called with the file path.
     *
     * @param  callable(string): void|null            $onNotArray
     * @return \Generator<array{string, array<mixed>}>
     */
    public static function records(
        Context $context,
        Reporter $reporter,
        string $subject,
        ?callable $onNotArray = null,
    ): \Generator {
        $files = self::files($context);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register();

        foreach ($files as $relative) {
            try {
                $records = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate managed file outside CiviCRM ({$e->getMessage()}) — $subject");
                continue;
            }
            if (!is_array($records)) {
                if ($onNotArray !== null) {
                    $onNotArray($relative);
                }
                continue;
            }
            yield [$relative, $records];
        }
    }

    /** @return list<string> */
    public static function files(Context $context): array
    {
        return $context->isGitRepo()
            ? $context->trackedUnder('', ['.mgd.php'])
            : $context->findFiles('', ['.mgd.php']);
    }
}
