<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * A catch around direct database traffic that makes no noise, and how it is
 * reported. Shared by the analyser rules that look for it.
 */
final class DatabaseCatch
{
    /** Log levels that reach a production log. */
    public const LOUD_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    private function __construct()
    {
    }

    public static function message(bool $emptyCatch): string
    {
        return $emptyCatch
            ? 'Empty catch around a database call — the query can fail and nothing will say so. '
                . 'Rethrow, or \\Civi::log()->error() with the context.'
            : 'Catch around a database call neither rethrows nor logs at error level — a failed query '
                . 'becomes a substituted value the caller cannot tell from real data. '
                . 'Rethrow, or \\Civi::log()->error() with the context.';
    }
}
