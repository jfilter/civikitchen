<?php

declare(strict_types=1);

final class PlainCaller
{
    public function call(): string
    {
        return (new OldGreeter())->greet() . OldGreeter::shout() . old_greet() . OldGreeter::LEVEL;
    }
}

/** @ck-legacy */
final class MarkedClass implements OldContract
{
    use OldHelpers;

    public function call(): string
    {
        return (new OldGreeter())->greet() . OldGreeter::shout() . old_greet();
    }
}

final class MarkedMethod
{
    /** @ck-legacy exercises the old greeter until it is gone */
    public function call(): string
    {
        return OldGreeter::shout();
    }

    public function unmarked(): string
    {
        return old_greet();
    }
}

/** @ck-legacy */
function marked_function(): string
{
    return old_greet();
}

/** @ck-legacy-ish is not the marker */
function near_miss(): string
{
    return old_greet();
}

/** @ck-legacy */
trait MarkedTrait
{
    public function viaTrait(): string
    {
        return old_greet();
    }
}

$closure = static fn (): string => old_greet();

abstract class AttributedMethod
{
    /** @ck-legacy the marker sits above the attribute */
    #[\ReturnTypeWillChange]
    public function call(): string
    {
        $inner = function (): string {
            return old_greet();
        };

        return $inner();
    }

    /** @ck-legacy */
    abstract public function nothing(): void;

    public function afterAbstract(): string
    {
        return "{$this->call()}" . old_greet();
    }
}

interface MarkedSignature
{
    /** @ck-legacy a bodiless declaration marks nothing after it */
    public function legacy(): void;
}

if (PHP_VERSION_ID > 0) {
    echo old_greet();
}
