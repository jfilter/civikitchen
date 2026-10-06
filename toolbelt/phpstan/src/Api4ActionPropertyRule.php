<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
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
 * publishes them, and a caller may leave any of them unset. A
 * property without a default therefore does not produce the API
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
 * The same holds for `?T` and `mixed`: a typed property without a default
 * starts uninitialized whatever its type. Properties a trait contributes are
 * parameters too, and one the constructor assigns is initialized. A declared
 * getter that reads it only through `??` or isset() is what the kernel calls, so
 * it is safe too.
 *
 * The mirror image is reported too: `@required` next to a default the kernel
 * accepts (anything but null, '', [] or FALSE) promises a validation that
 * cannot happen, because the parameter is never missing.
 *
 * @implements Rule<InClassNode>
 */
final class Api4ActionPropertyRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $class = $node->getOriginalNode();
        if (!self::isApi4Action($classReflection, $node)) {
            return [];
        }
        $initialized = self::assignedInConstructor($class);

        $errors = [];
        foreach (self::parameters($class, $classReflection) as [$name, $type, $hasDefault, $emptyDefault, $required, $line]) {
            if (str_starts_with($name, '_') || isset($initialized[$name]) || self::hasGuardedGetter($class, $name)) {
                continue;
            }
            // `@required` is deliberately not an escape here: the kernel
            // reads the parameter before it checks the requirement.
            if ($type !== null && !$hasDefault) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'APIv4 action parameter $%s is typed %s with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks '
                    . '@required.%s Declare it untyped with an @var docblock and @required (core\'s own form for a '
                    . 'mandatory parameter), or give it a default.',
                    $name,
                    $type,
                    $required ? ' @required does not prevent this.' : '',
                ))->identifier('ck.api4.uninitializedActionParam')->line($line)->build();

                continue;
            }
            if ($required && $hasDefault && !$emptyDefault) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'APIv4 action parameter $%s is marked @required but has a default, so the kernel never sees '
                    . 'it missing and the requirement is never enforced.',
                    $name,
                ))->identifier('ck.api4.requiredActionParamWithDefault')->line($line)->build();
            }
        }

        return $errors;
    }

    /**
     * The protected, non-static properties of the class and of its traits:
     * name, type (null when untyped), default, empty default, @required, line.
     * A trait's properties are reported on its `use` line.
     *
     * @return list<array{string, ?string, bool, bool, bool, int}>
     */
    private static function parameters(Node\Stmt\ClassLike $class, ClassReflection $reflection): array
    {
        $parameters = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property && $stmt->isProtected() && !$stmt->isStatic()) {
                foreach ($stmt->props as $property) {
                    // An untyped property without a default is implicitly null.
                    $default = $property->default ?? ($stmt->type === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : null);
                    $parameters[$property->name->toString()] = [
                        $property->name->toString(),
                        $stmt->type === null ? null : self::typeToString($stmt->type),
                        $default !== null,
                        $default !== null && self::isEmptyDefault($default),
                        self::hasRequiredTag($stmt->getDocComment()?->getText()),
                        $stmt->getStartLine(),
                    ];
                }
            }
            if (!$stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $traitName) {
                $trait = self::traitReflection($reflection, $traitName->toString());
                foreach ($trait?->getNativeReflection()->getProperties() ?? [] as $property) {
                    if (!$property->isProtected() || $property->isStatic() || isset($parameters[$property->getName()])) {
                        continue;
                    }
                    $parameters[$property->getName()] = [
                        $property->getName(),
                        $property->hasType() ? (string) $property->getType() : null,
                        $property->hasDefaultValue(),
                        $property->hasDefaultValue() && in_array($property->getDefaultValue(), [null, '', [], false], true),
                        self::hasRequiredTag($property->getDocComment() ?: null),
                        $stmt->getStartLine(),
                    ];
                }
            }
        }

        return array_values($parameters);
    }

    private static function traitReflection(ClassReflection $class, string $name): ?ClassReflection
    {
        foreach ($class->getTraits(true) as $trait) {
            if (strcasecmp($trait->getName(), $name) === 0) {
                return $trait;
            }
        }

        return null;
    }

    /**
     * Properties the constructor assigns through `$this->x = ...`.
     *
     * @return array<string, true>
     */
    private static function assignedInConstructor(Node\Stmt\ClassLike $class): array
    {
        $constructor = $class->getMethod('__construct');
        $assigned = [];
        foreach ((new NodeFinder())->findInstanceOf($constructor?->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            $target = $assign->var;
            if ($target instanceof Node\Expr\PropertyFetch && $target->var instanceof Node\Expr\Variable
                && $target->var->name === 'this' && $target->name instanceof Node\Identifier) {
                $assigned[$target->name->toString()] = true;
            }
        }

        return $assigned;
    }

    /**
     * A declared `get<Name>()` that reads the property only inside `??`, isset(), empty()
     * or the branch of a ternary or if where it is set — reads an uninitialized one survives.
     */
    private static function hasGuardedGetter(Node\Stmt\ClassLike $class, string $name): bool
    {
        $stmts = $class->getMethod('get' . ucfirst($name))?->stmts;
        if ($stmts === null) {
            return false;
        }
        $finder = new NodeFinder();
        $isRead = static fn (Node $node): bool => $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && $node->name->toString() === $name;
        $safe = [];
        foreach ($finder->find($stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Isset_
            || $node instanceof Node\Expr\Empty_ || $node instanceof Node\Expr\BinaryOp\Coalesce || $node instanceof Node\Expr\AssignOp\Coalesce
            || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\Ternary || $node instanceof Node\Stmt\If_) as $guard) {
            // These read their operand without the uninitialized error, but not its offsets or call arguments.
            $operands = match (true) {
                $guard instanceof Node\Expr\Isset_ => $guard->vars,
                $guard instanceof Node\Expr\Empty_ => [$guard->expr],
                $guard instanceof Node\Expr\BinaryOp\Coalesce => [$guard->left],
                // `??=` reads like `??`, and an assignment target is a write.
                $guard instanceof Node\Expr\AssignOp\Coalesce, $guard instanceof Node\Expr\Assign => [$guard->var],
                default => [],
            };
            foreach (array_merge(...array_map(self::fetchChain(...), $operands)) as $link) {
                if ($isRead($link)) {
                    $safe[spl_object_id($link)] = true;
                }
            }
            $presence = $guard instanceof Node\Expr\Ternary || $guard instanceof Node\Stmt\If_ ? self::presence($guard->cond, $isRead) : null;
            $covered = match (true) {
                $guard instanceof Node\Expr\Ternary && $presence === true && $guard->if !== null => [$guard->if],
                $guard instanceof Node\Expr\Ternary && $presence === false => [$guard->else],
                $guard instanceof Node\Stmt\If_ && $presence === true => $guard->stmts,
                $guard instanceof Node\Stmt\If_ && $presence === false && $guard->elseifs === [] => $guard->else->stmts ?? [],
                default => [],
            };
            foreach ($finder->find($covered, $isRead) as $read) {
                $safe[spl_object_id($read)] = true;
            }
        }
        $reads = $finder->find($stmts, $isRead);
        $unsafe = array_diff_key(array_flip(array_map(spl_object_id(...), $finder->find(self::untilInitialised($stmts, $isRead), $isRead))), $safe);

        return $reads !== [] && $unsafe === [];
    }

    /**
     * The expression and the bases it is fetched from: `$this->x` in `$this->x['k']->y`, not the offset.
     *
     * @return list<Node\Expr>
     */
    private static function fetchChain(Node\Expr $expr): array
    {
        $chain = [$expr];
        while ($expr instanceof Node\Expr\ArrayDimFetch || $expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            $expr = $expr->var;
            $chain[] = $expr;
        }

        return $chain;
    }

    /**
     * The statements up to the one that initialises the property — inside an initialising
     * if, its condition and its body up to the assignment — after which reads are safe.
     *
     * @param  array<Node\Stmt>        $stmts
     * @param  callable(Node): bool     $isRead
     * @return list<Node>
     */
    private static function untilInitialised(array $stmts, callable $isRead): array
    {
        $nodes = [];
        foreach ($stmts as $stmt) {
            if (!self::initialises($stmt, $isRead)) {
                $nodes[] = $stmt;
                continue;
            }
            if ($stmt instanceof Node\Stmt\If_) {
                return [...$nodes, $stmt->cond, ...self::untilInitialised($stmt->stmts, $isRead)];
            }

            return [...$nodes, $stmt];
        }

        return $nodes;
    }

    /**
     * TRUE for `isset($this->x)` and `!empty($this->x)`, FALSE for their negations, else NULL.
     *
     * @param callable(Node): bool $isRead
     */
    private static function presence(Node\Expr $expr, callable $isRead): ?bool
    {
        $negated = $expr instanceof Node\Expr\BooleanNot;
        $inner = $negated ? $expr->expr : $expr;

        return match (true) {
            $inner instanceof Node\Expr\Isset_ && array_filter($inner->vars, $isRead) !== [] => !$negated,
            $inner instanceof Node\Expr\Empty_ && $isRead($inner->expr) => $negated,
            default => null,
        };
    }

    /**
     * `$this->x ??= …;`, `$this->x = …;`, or `if (!isset($this->x)) { … }` without else
     * branches whose body assigns the property or ends in return or throw.
     *
     * @param callable(Node): bool $isRead
     */
    private static function initialises(Node\Stmt $stmt, callable $isRead): bool
    {
        if ($stmt instanceof Node\Stmt\Expression) {
            return ($stmt->expr instanceof Node\Expr\Assign || $stmt->expr instanceof Node\Expr\AssignOp\Coalesce)
                && $isRead($stmt->expr->var);
        }

        if (!$stmt instanceof Node\Stmt\If_ || $stmt->else !== null || $stmt->elseifs !== [] || self::presence($stmt->cond, $isRead) !== false) {
            return false;
        }
        $last = end($stmt->stmts);

        return $last instanceof Node\Stmt\Return_
            || ($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_)
            || array_filter($stmt->stmts, static fn (Node\Stmt $inner): bool => self::initialises($inner, $isRead)) !== [];
    }

    /** null, '', [] and FALSE, which ValidateFieldsSubscriber treats as missing. */
    private static function isEmptyDefault(Node\Expr $default): bool
    {
        if ($default instanceof Node\Expr\ConstFetch) {
            return in_array($default->name->toLowerString(), ['null', 'false'], true);
        }

        return ($default instanceof Node\Scalar\String_ && $default->value === '')
            || ($default instanceof Node\Expr\Array_ && $default->items === []);
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

    private static function hasRequiredTag(?string $doc): bool
    {
        return $doc !== null && preg_match('/@required\b/', $doc) === 1;
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
