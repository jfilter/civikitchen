<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Registry;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Keeps the inline ignores honest.
 *
 * Three failure modes, all silent by nature: a `ckconform-ignore` without the
 * mandatory `-- <reason>` suppresses nothing (and its author believes it
 * does), one naming a check that does not exist is a dead ignore that never
 * matches — usually a typo'd check name, the exact class of bug the hook
 * checks exist to catch in hook names — and one that silenced nothing this run
 * outlived its finding, so the next real one under it would be swallowed
 * unnoticed. That last one is phpstan's `reportUnmatchedIgnoredErrors`.
 *
 * This check is last in the registry precisely for the third: consumption is
 * recorded on the shared Suppressions instances as the other checks match
 * against them, so the tally is only complete once they have all run. Re-read
 * the files here, but not re-parse them — `Suppressions::of()` hands back the
 * very instance the earlier checks marked up.
 *
 * Not reported as unused: a name for a check `ignore_checks=` skipped (nothing
 * looked for the finding, so nothing can be concluded) and a name no check
 * carries (already reported above — one confused ignore, one message).
 */
final class SuppressionHygieneCheck implements Check
{
    public function name(): string
    {
        return 'suppression-hygiene';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $known = null;

        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'ckconform-ignore')) {
                continue;
            }
            $suppressions = Suppressions::of($contents);

            foreach ($suppressions->missingReason() as $line) {
                $reporter->warn(sprintf(
                    '%s:%d: ckconform-ignore without a reason suppresses nothing — write `ckconform-ignore <check> -- <reason>`',
                    $file,
                    $line,
                ));
            }

            $known ??= array_map(static fn (Check $c): string => $c->name(), Registry::all());
            foreach ($suppressions->entries() as $entry) {
                foreach (array_diff($entry['names'], $known) as $unknown) {
                    $reporter->warn(sprintf(
                        "%s:%d: ckconform-ignore names an unknown check '%s' — a dead ignore never matches",
                        $file,
                        $entry['line'],
                        $unknown,
                    ));
                }
            }

            $skipped = $context->skippedChecks();
            foreach ($suppressions->unconsumed() as $unused) {
                if (!in_array($unused['name'], $known, true)
                    || in_array($unused['name'], $skipped, true)
                ) {
                    continue;
                }
                $reporter->warn(sprintf(
                    "%s:%d: ckconform-ignore%s for '%s' suppressed nothing — the finding it silenced is gone, remove it",
                    $file,
                    $unused['line'],
                    $unused['file'] ? '-file' : '',
                    $unused['name'],
                ));
            }
        }
    }
}
