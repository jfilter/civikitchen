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
 * Two failure modes, both silent by nature: a `ckconform-ignore` without the
 * mandatory `-- <reason>` suppresses nothing (and its author believes it
 * does), and one naming a check that does not exist is a dead ignore that
 * never matches — usually a typo'd check name, the exact class of bug the
 * hook checks exist to catch in hook names.
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
        }
    }
}
