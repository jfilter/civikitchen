<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\PropertyInitializationProvider;
use Mago\Sdk\Analyzer\PropertyInitializationProviderContext;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\Type\Visibility;

/**
 * API parameters are written by the kernel, not by the action that declares
 * them, so `check-property-initialization` must not report them.
 */
final class Api4ActionPropertiesHook implements PropertyInitializationProvider
{
    public function getTargets(): array
    {
        // A trait property arrives with the trait as its declaring class, which no class target matches.
        return [PropertyTarget::anyClass('*')];
    }

    /** core's getParamInfo() reads protected, non-static properties only; `_` marks internal state. */
    public function isPropertyInitialized(PropertyInitializationProviderContext $context): bool
    {
        $property = $context->property;
        if ($property->readVisibility !== Visibility::Protected
            || $property->flags->contains(MetadataFlags::STATIC)
            || str_starts_with(ltrim($property->name, '$'), '_')) {
            return false;
        }
        $declaring = strtolower(ltrim($context->declaringClass, '\\'));
        $actions = array_map('strtolower', $context->codebase->getClassDescendants('Civi\\Api4\\Generic\\AbstractAction'));
        if (in_array($declaring, $actions, true)) {
            return true;
        }

        // A trait's property is a parameter when an action uses the trait directly.
        return $context->codebase->traitExists($declaring) && array_filter(
            $context->codebase->getMultipleClasses($actions),
            static fn (?ClassLikeMetadata $action): bool => in_array($declaring, array_map('strtolower', $action->usedTraits ?? []), true),
        ) !== [];
    }
}
