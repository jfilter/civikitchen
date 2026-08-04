<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * API parameters are written by the kernel, not by the class that owns them.
 *
 * `checkUninitializedProperties` is worth having on, but on an APIv4 action
 * every parameter would report: nothing in the extension's own code ever
 * assigns them — `Contact::get()->setLimit(5)` goes through the generic
 * `__call` magic, and the array form through the kernel's own hydration.
 * Reporting those is noise, and noise is how a fleet learns to ignore a gate.
 *
 * So the extension declares them written, read and initialized, and
 * Api4ActionPropertyRule makes the one statement that is actually true of
 * such a property: without @required or a default, omitting it is a PHP
 * error rather than an API error.
 */
final class Api4ActionPropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    /** A protected property without a leading underscore on an action class. */
    private static function isApiParameter(PropertyReflection $property, string $propertyName): bool
    {
        if (str_starts_with($propertyName, '_') || $property->isPrivate() || $property->isStatic()) {
            return false;
        }

        for ($class = $property->getDeclaringClass(); $class !== null; $class = $class->getParentClass()) {
            if ($class->getName() === 'Civi\\Api4\\Generic\\AbstractAction') {
                return true;
            }
        }

        return false;
    }
}
