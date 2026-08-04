<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

/**
 * Reading the entity out of a fluent APIv4 chain.
 *
 * `\Civi\Api4\Contact::get()->addWhere(...)->execute()` parses as nested
 * method calls whose innermost node is the static call; both rules that care
 * about the chain start there.
 */
final class Api4Fluent
{
    /** Namespace segments under Civi\Api4\ that are not entities. */
    private const NOT_ENTITIES = ['Generic', 'Action', 'Utils', 'Query', 'Service', 'Event', 'Provider'];

    /** The entity name a `\Civi\Api4\X::y()` call addresses. */
    public static function entityFromStaticCall(StaticCall $node, Scope $scope): ?string
    {
        if (!$node->class instanceof Name) {
            return null;
        }
        $class = $scope->resolveName($node->class);
        if (!str_starts_with($class, 'Civi\\Api4\\')) {
            return null;
        }
        $rest = substr($class, strlen('Civi\\Api4\\'));
        if (str_contains($rest, '\\') || in_array($rest, self::NOT_ENTITIES, true)) {
            return null;
        }

        return Api4Catalog::CLASS_ALIASES[$rest] ?? $rest;
    }

    /**
     * Aliases the chain has already defined: `SUM(line_total) AS total`.
     *
     * An alias is a legal name in orderBy and groupBy but exists in no
     * catalog, so the earlier links of the chain have to be read before the
     * later ones can be judged. Only the links below this node are visible —
     * a chain that orders before it selects is not resolvable here, and the
     * expression check then quietly lets the name pass.
     *
     * @return list<string>
     */
    public static function aliasesOfChain(MethodCall $node, Scope $scope): array
    {
        $aliases = [];
        $expr = $node->var;
        while ($expr instanceof MethodCall) {
            if ($expr->name instanceof Identifier && in_array($expr->name->toLowerString(), ['addselect', 'setselect'], true)) {
                foreach ($expr->getArgs() as $arg) {
                    $type = $scope->getType($arg->value);
                    $strings = array_merge(
                        $type->getConstantStrings(),
                        ...array_map(
                            static fn ($array) => $array->getValuesArray()->getConstantStrings(),
                            $type->getConstantArrays(),
                        ),
                    );
                    foreach ($strings as $string) {
                        $alias = self::aliasOf($string->getValue());
                        if ($alias !== null) {
                            $aliases[] = $alias;
                        }
                    }
                }
            }
            $expr = $expr->var;
        }

        return $aliases;
    }

    /** The name a select expression binds, if it binds one. */
    private static function aliasOf(string $select): ?string
    {
        if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $select, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The entity of the chain a method call hangs off, if it is a chain. */
    public static function entityOfChain(MethodCall $node, Scope $scope): ?string
    {
        $expr = $node->var;
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }
        if (!$expr instanceof StaticCall) {
            return null;
        }

        return self::entityFromStaticCall($expr, $scope);
    }

    /**
     * The entity behind a builder the chain does not start from.
     *
     * `$query = Contact::get(); $query->addSelect(...)` is one statement too
     * many for the AST walk, but the type is exact: core generates one action
     * class per entity and action, `Civi\Api4\Action\Contact\Get`. Anything
     * that resolves to a generic action base names no entity and is passed
     * over.
     *
     * The aliases an earlier link defined are invisible from here, so callers
     * must only use this where an alias would not be a legal name.
     */
    public static function entityOfReceiverType(MethodCall $node, Scope $scope): ?string
    {
        $classes = $scope->getType($node->var)->getObjectClassNames();
        if (count($classes) !== 1) {
            return null;
        }
        $parts = explode('\\', $classes[0]);
        if (count($parts) !== 5 || $parts[0] !== 'Civi' || $parts[1] !== 'Api4' || $parts[2] !== 'Action') {
            return null;
        }
        $entity = Api4Catalog::CLASS_ALIASES[$parts[3]] ?? $parts[3];

        return Api4Catalog::knowsEntity($entity) ? $entity : null;
    }
}
