<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The verdict on one APIv4 action parameter, shared by the phpstan rule and
 * the mago hook so both report the same thing with the same words.
 */
final class Api4ActionParameter
{
    private function __construct()
    {
    }

    /**
     * The finding for one parameter, as identifier suffix and message; null when it is sound.
     *
     * @return ?array{string, string}
     */
    public static function problem(string $name, ?string $type, bool $hasDefault, bool $emptyDefault, bool $required): ?array
    {
        // `@required` is deliberately not an escape here: the kernel
        // reads the parameter before it checks the requirement.
        if ($type !== null && !$hasDefault) {
            return ['uninitializedActionParam', sprintf(
                'APIv4 action parameter $%s is typed %s with no default — a caller that omits it gets '
                . '"must not be accessed before initialization" instead of an API validation error, because '
                . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks '
                . '@required.%s Declare it untyped with an @var docblock and @required (core\'s own form for a '
                . 'mandatory parameter), or give it a default.',
                $name,
                $type,
                $required ? ' @required does not prevent this.' : '',
            )];
        }
        if ($required && $hasDefault && !$emptyDefault) {
            return ['requiredActionParamWithDefault', sprintf(
                'APIv4 action parameter $%s is marked @required but has a default, so the kernel never sees '
                . 'it missing and the requirement is never enforced.',
                $name,
            )];
        }

        return null;
    }

    /** The textual fallback for a run that cannot see core. */
    public static function isGenericActionBase(string $parentName): bool
    {
        return str_starts_with($parentName, 'Civi\\Api4\\Generic\\Abstract')
            && str_ends_with($parentName, 'Action');
    }

    public static function hasRequiredTag(?string $doc): bool
    {
        return $doc !== null && preg_match('/@required\b/', $doc) === 1;
    }
}
