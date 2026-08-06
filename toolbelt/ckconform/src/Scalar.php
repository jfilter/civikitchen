<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/** Rendering arbitrary config values inside a finding message. */
final class Scalar
{
    /** The value itself when printable, its type when not. */
    public static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
