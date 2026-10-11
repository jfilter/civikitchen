<?php

declare(strict_types=1);

/** @deprecated use Greeter */
class OldGreeter
{
    /** @deprecated use greetAll() */
    public const LEVEL = 1;

    public function greet(): string
    {
        return 'hi';
    }

    /** @deprecated */
    public static function shout(): string
    {
        return 'HI';
    }
}

/** @deprecated */
function old_greet(): string
{
    return 'hi';
}

/** @deprecated */
interface OldContract
{
}

/** @deprecated */
trait OldHelpers
{
}
