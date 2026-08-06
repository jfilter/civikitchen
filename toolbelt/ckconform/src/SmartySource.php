<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/** Shared preprocessing for the checks that scan Smarty templates as text. */
final class SmartySource
{
    /**
     * Smarty comments and `{literal}` blocks, replaced by spaces of the same
     * length: their contents are never parsed as tags, and blanking rather than
     * deleting keeps every remaining offset — and so every reported line number
     * — correct.
     */
    public static function blankOutInertRegions(string $source): string
    {
        return (string) preg_replace_callback(
            ['/\{\*.*?\*\}/s', '#\{literal\}.*?\{/literal\}#si'],
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? $m[0],
            $source,
        );
    }
}
