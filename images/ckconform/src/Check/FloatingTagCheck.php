<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * ':latest' and 'releases/latest/download' in CI make a green run
 * unreproducible and a red one unattributable — the workflow can start
 * building against a different image or binary tomorrow with no diff to
 * point at.
 *
 * The bash original only ever surfaces the first hit (`grep -n ... | head -3`
 * feeding `head -1 | cut -c1-70`), so this stops at the first match too.
 */
final class FloatingTagCheck implements Check
{
    public function name(): string
    {
        return 'floating-tag';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->workflows() as $workflow) {
            $contents = $context->read($workflow);
            if ($contents === null) {
                continue;
            }
            $lines = explode("\n", $contents);
            // A trailing newline yields a trailing empty element that grep
            // never numbers as a line; drop it so line numbers stay true.
            if ($lines !== [] && $lines[array_key_last($lines)] === '') {
                array_pop($lines);
            }
            foreach ($lines as $index => $line) {
                if ($this->isFloating($line)) {
                    $match = $workflow . ':' . ($index + 1) . ':' . $line;
                    $reporter->warn('CI pins nothing (floating :latest): ' . substr($match, 0, 70));

                    return;
                }
            }
        }
    }

    private function isFloating(string $line): bool
    {
        return (bool) preg_match('/image:.*:latest|releases\/latest\/download/', $line);
    }
}
