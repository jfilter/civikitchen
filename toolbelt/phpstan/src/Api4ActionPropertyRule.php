<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * API parameters declared as properties of a custom APIv4 action.
 *
 * On a subclass of AbstractAction every protected property without a leading
 * underscore IS an API parameter: the generic action reads them, `getFields`
 * publishes them, and a caller may leave any of them unset. A non-nullable
 * typed property without a default therefore does not produce the API
 * validation error the author expected — it produces PHP's "must not be
 * accessed before initialization" Error, from inside the API kernel, with a
 * stack trace that names no field.
 *
 * `@required` does NOT save it, which is the whole point of this rule:
 * ValidateFieldsSubscriber::onApiPrepare() reads every parameter through its
 * getter FIRST and only then asks whether it was required, so the read that
 * fatals happens before the check that would have produced
 * `Parameter "x" is required.`. Verified in a running install. Core's own
 * dominant form for a mandatory parameter is therefore untyped: a property
 * with no default, an `@var` docblock for the type and `@required`.
 *
 * The mirror image is reported too: `@required` next to a default (or a
 * nullable type) promises a validation that cannot happen, because the
 * parameter is never missing.
 *
 * @implements Rule<InClassNode>
 */
final class Api4ActionPropertyRule implements Rule
{
    private bool $strict;

    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        if (!self::isApi4Action($classReflection, $node)) {
            return [];
        }

        $errors = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Property || !$stmt->isProtected() || $stmt->isStatic()) {
                continue;
            }
            // An untyped property is implicitly null — never uninitialized.
            if ($stmt->type === null) {
                continue;
            }
            $required = self::hasRequiredTag($stmt);
            $nullable = self::isNullable($stmt->type);

            foreach ($stmt->props as $property) {
                $name = $property->name->toString();
                if (str_starts_with($name, '_')) {
                    continue;
                }
                $default = $property->default !== null;

                // `@required` is deliberately not an escape here: the kernel
                // reads the parameter before it checks the requirement, so
                // both spellings fatal the same way.
                if (!$default && !$nullable) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'APIv4 action parameter $%s is typed %s with no default — a caller that omits it gets '
                        . '"must not be accessed before initialization" instead of an API validation error, because '
                        . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks '
                        . '@required.%s Declare it untyped with an @var docblock and @required (core\'s own form for a '
                        . 'mandatory parameter), or give it a default.',
                        $name,
                        self::typeToString($stmt->type),
                        $required ? ' @required does not prevent this.' : '',
                    ))->identifier('ck.api4.uninitializedActionParam')->line($stmt->getStartLine())->build();

                    continue;
                }

                // `?string $x;` with no default is uninitialized until the
                // kernel fills it — usually intended, occasionally a bug, so
                // it is a separate identifier a repo turns on for itself.
                if ($this->strict && !$required && !$default && $nullable) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'APIv4 action parameter $%s is nullable with no default, so it is uninitialized rather than null '
                        . 'until the kernel writes it — give it a default of null to make that explicit.',
                        $name,
                    ))->identifier('ck.api4.nullableActionParamWithoutDefault')->line($stmt->getStartLine())->build();

                    continue;
                }

                if ($required && ($default || $nullable)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'APIv4 action parameter $%s is marked @required but %s, so the kernel never sees it missing '
                        . 'and the requirement is never enforced.',
                        $name,
                        $default ? 'has a default' : 'is nullable',
                    ))->identifier('ck.api4.requiredActionParamWithDefault')->line($stmt->getStartLine())->build();
                }
            }
        }

        return $errors;
    }

    /**
     * Whether the class is a custom APIv4 action.
     *
     * Reflection first, so an own intermediate base class counts. The textual
     * fallback keeps the rule alive in a run that cannot see core: every
     * generic action base is `Civi\Api4\Generic\Abstract*Action`.
     */
    private static function isApi4Action(ClassReflection $class, InClassNode $node): bool
    {
        for ($parent = $class->getParentClass(); $parent !== null; $parent = $parent->getParentClass()) {
            if ($parent->getName() === 'Civi\\Api4\\Generic\\AbstractAction') {
                return true;
            }
        }

        $original = $node->getOriginalNode();
        if (!$original instanceof Node\Stmt\Class_ || $original->extends === null) {
            return false;
        }
        $parentName = $original->extends->toString();

        return str_starts_with($parentName, 'Civi\\Api4\\Generic\\Abstract')
            && str_ends_with($parentName, 'Action');
    }

    private static function hasRequiredTag(Node\Stmt\Property $property): bool
    {
        $doc = $property->getDocComment();

        return $doc !== null && preg_match('/@required\b/', $doc->getText()) === 1;
    }

    private static function isNullable(Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if ($member instanceof Node\Identifier && $member->toLowerString() === 'null') {
                    return true;
                }
            }
        }

        return $type instanceof Node\Identifier
            && in_array($type->toLowerString(), ['null', 'mixed'], true);
    }

    private static function typeToString(Node $type): string
    {
        if ($type instanceof Node\NullableType) {
            return '?' . self::typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([self::class, 'typeToString'], $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map([self::class, 'typeToString'], $type->types));
        }

        return $type instanceof Node\Name ? $type->toString() : (string) $type;
    }
}
