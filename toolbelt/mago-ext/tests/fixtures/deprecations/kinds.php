<?php

declare(strict_types=1);

namespace Acme\Widget;

/** @deprecated use Greeter */
trait OldGreeting
{
}

/** @deprecated */
interface OldContract
{
}

final class Thing implements OldContract
{
    use OldGreeting;

    /** @deprecated */
    public const OLD = 1;

    public function value(): int
    {
        return self::OLD;
    }
}
