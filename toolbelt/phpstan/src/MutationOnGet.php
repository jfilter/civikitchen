<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * Which handlers a browser can GET, how far a write is followed, and how it
 * is reported. Shared by the analyser rules that look for it.
 */
final class MutationOnGet
{
    /** The base whose run() is a route. */
    public const PAGE_BASE = 'CRM_Core_Page';

    /** Own-method calls followed from a handler. */
    public const MAX_HOPS = 2;

    private const ADVICE = 'a route a browser can GET must not change data — check the request method, or document the exception with %s.';

    private function __construct()
    {
    }

    /** $route is the declared path, or '' for a page class reachable without one; $suppression is the tool's ignore comment. */
    public static function message(string $what, string $handler, string $route, string $suppression): string
    {
        return sprintf(
            '%s writes from %s, which %s — %s',
            $what,
            $handler,
            $route === '' ? 'is reachable by GET' : sprintf('answers GET %s', $route),
            sprintf(self::ADVICE, $suppression),
        );
    }
}
