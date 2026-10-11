<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Reflection\ReflectionProvider;

/** ClassLookup over phpstan's reflection. */
final class ReflectionClassLookup implements ClassLookup
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function declaredName(string $class): ?string
    {
        return $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class)->getName() : null;
    }
}
