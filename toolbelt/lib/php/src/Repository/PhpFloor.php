<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Repository;

/**
 * The lowest PHP MAJOR.MINOR a composer `require.php` constraint admits: the
 * smallest lower bound across its `||` alternatives.
 */
final class PhpFloor
{
    /** Null when some alternative has no lower bound (`*`, `<8.4`) or the constraint is empty. */
    public static function of(string $constraint): ?string
    {
        $floor = null;
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $lower = self::lowerBound($alternative);
            if ($lower === null) {
                return null;
            }
            if ($floor === null || version_compare($lower, $floor, '<')) {
                $floor = $lower;
            }
        }
        return $floor;
    }

    /** The floor of composer.json's require.php in $directory, null when it declares none. */
    public static function ofComposerJson(string $directory): ?string
    {
        $file = $directory . '/composer.json';
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $constraint = is_array($composer) ? ($composer['require']['php'] ?? null) : null;
        return is_string($constraint) ? self::of($constraint) : null;
    }

    /** An alternative's terms all apply, so its bound is the highest one; `a - b` keeps only `a`. */
    private static function lowerBound(string $alternative): ?string
    {
        $alternative = (string) preg_replace(['/\s+-\s+\S+/', '/(!=|<>|[<>]=?|==?|[~^])\s+/'], ['', '$1'], $alternative);
        $lower = null;
        foreach (preg_split('/[\s,]+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (str_starts_with($term, '<') || str_starts_with($term, '!=')
                || preg_match('/^[>=~^v]*(\d+)(?:\.(\d+))?/', $term, $match) !== 1) {
                continue;
            }
            $version = $match[1] . '.' . ($match[2] ?? '0');
            if ($lower === null || version_compare($version, $lower, '>')) {
                $lower = $version;
            }
        }
        return $lower;
    }
}
