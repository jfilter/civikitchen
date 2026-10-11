<?php

declare(strict_types=1);

/** @deprecated use modernHelper() */
function legacyHelper(): string
{
    return 'x';
}

/** @deprecated use Modern */
final class Legacy
{
    /** @deprecated */
    public static function old(): void
    {
    }
}

final class Caller
{
    public function run(\ReflectionProperty $property): string
    {
        Legacy::old();
        $property->setAccessible(true);
        new Legacy();

        return legacyHelper() . utf8_encode('a');
    }
}
