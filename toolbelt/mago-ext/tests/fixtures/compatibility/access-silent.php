<?php

declare(strict_types=1);

namespace Acme;

use Random\IntervalBoundary;

final class Bounded
{
    public const NAME = 'bounded';

    public function name(): string
    {
        return self::NAME . static::NAME . \DateTimeInterface::ATOM . \PHP_EOL . E_ALL;
    }

    public function check(mixed $value, IntervalBoundary $boundary): bool
    {
        return $value instanceof \Random\IntervalBoundary && IntervalBoundary::class !== '' && $boundary !== null;
    }
}

function guarded(): ?object
{
    return enum_exists(IntervalBoundary::class) ? IntervalBoundary::OpenOpen : null;
}

function defined_constant(): int
{
    return defined('CURLOPT_TCP_KEEPCNT') ? \CURLOPT_TCP_KEEPCNT : 0;
}
