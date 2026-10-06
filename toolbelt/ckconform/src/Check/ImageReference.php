<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

/**
 * A container image reference as written in YAML, judged for whether it floats:
 * `:latest`, or no tag at all, which Docker reads as `:latest`.
 */
final class ImageReference
{
    /** The reference without surrounding YAML quotes. */
    public static function unquote(string $reference): string
    {
        return trim(trim($reference), '"\'');
    }

    /**
     * Interpolations (`${VAR}`, `${{ matrix.x }}`) and anything that is no plain
     * reference (a flow list, a block scalar) are not judged; a digest pins.
     */
    public static function floats(string $reference): bool
    {
        $image = self::unquote($reference);
        if (preg_match('/^[a-z0-9][a-z0-9._\/:@-]*$/i', $image) !== 1 || str_contains($image, '@')) {
            return false;
        }
        if (str_ends_with($image, ':latest')) {
            return true;
        }
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');

        return $lastColon === false || ($lastSlash !== false && $lastColon < $lastSlash);
    }
}
