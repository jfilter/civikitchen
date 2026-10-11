<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The one question the APIv4 contract asks of the analysed code base, so
 * phpstan and mago can each answer it from their own class index.
 */
interface ClassLookup
{
    /** The declared spelling of a class-like, or null when the analysis knows none. */
    public function declaredName(string $class): ?string;
}
