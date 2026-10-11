<?php

declare(strict_types=1);

namespace Acme\Widget;

use Dom\HTMLDocument as Doc;

final class Page
{
    public function document(): ?object
    {
        return class_exists(Doc::class) ? Doc::createEmpty() : null;
    }

    public function stamp(): ?\DateTimeImmutable
    {
        return method_exists($this->clock(), 'createFromTimestamp') ? \DateTimeImmutable::createFromTimestamp(0) : null;
    }

    private function clock(): string
    {
        return \DateTimeImmutable::class;
    }
}
