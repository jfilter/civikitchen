<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\ClassLookup;
use Mago\Sdk\Analyzer\Codebase;

/** ClassLookup over mago's codebase metadata. */
final class CodebaseClassLookup implements ClassLookup
{
    public function __construct(private readonly Codebase $codebase)
    {
    }

    public function declaredName(string $class): ?string
    {
        return $this->codebase->getClassLike(ltrim($class, '\\'))?->originalName;
    }
}
