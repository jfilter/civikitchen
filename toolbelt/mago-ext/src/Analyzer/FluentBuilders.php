<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\Api4Contract;
use CiviKitchen\PHPStan\ClassLookup;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The APIv4 builders one function body holds. A builder is a chain rooted in
 * `\Civi\Api4\X::action()`, or a variable assigned exactly once from one; a
 * variable that leaves the body (an argument, a closure) `escapes`.
 */
final class FluentBuilders
{
    private const FUNCTION_LIKES = [NodeKind::Function, NodeKind::Method, NodeKind::Closure, NodeKind::ArrowFunction];

    private const CLASS_LIKES = [NodeKind::Class_, NodeKind::Interface, NodeKind::Trait, NodeKind::Enum, NodeKind::AnonymousClass];

    /** Subtrees of a function-like that are not its body. */
    private const NOT_BODY = [NodeKind::FunctionLikeParameterList, NodeKind::ClosureUseClause, NodeKind::AttributeList, NodeKind::FunctionLikeReturnTypeHint];

    /**
     * @return list<array{entity: string, links: list<Node>, escapes: bool}>
     */
    public static function of(SourceFile $source, Node $functionLike, ClassLookup $classes): array
    {
        $own = [];
        $captured = [];
        foreach ($source->getChildren($functionLike) as $child) {
            if (!in_array($child->kind, self::NOT_BODY, true)) {
                self::ownNodes($source, $child, $own, $captured);
            }
        }

        $writes = array_fill_keys(self::boundOutside($source, $functionLike), [null]);
        $occurrences = [];
        $inner = [];
        foreach ($own as $node) {
            if ($node->kind === NodeKind::DirectVariable) {
                $name = self::variableName($source, $node);
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
            } elseif ($node->kind === NodeKind::Assignment) {
                [$target, $operator, $value] = $source->getChildren($node);
                $variable = self::directVariable($source, $target);
                if ($variable !== null && $source->getText($operator) === '=') {
                    $writes[$variable][] = self::unwrap($source, $value);
                } else {
                    foreach (self::variablesIn($source, $target) as $name) {
                        $writes[$name][] = null;
                    }
                }
            } elseif ($node->kind === NodeKind::MethodCall) {
                $receiver = self::unwrap($source, $source->getChildren($node)[0]);
                if ($receiver->kind === NodeKind::MethodCall) {
                    $inner[$receiver->id] = true;
                }
            }
            foreach (self::otherWrites($source, $node) as $name) {
                $writes[$name][] = null;
            }
        }

        // name => entity, for variables written once, from an APIv4 chain.
        $variables = [];
        $assignedChain = [];
        foreach ($writes as $name => $values) {
            $root = count($values) === 1 && $values[0] !== null ? self::unwind($source, $values[0])[0] : null;
            $entity = $root?->kind === NodeKind::StaticMethodCall ? self::builderEntity($source, $root, $classes) : null;
            if ($entity !== null) {
                $variables[$name] = $entity;
                $assignedChain[$values[0]->id] = (string) $name;
            }
        }

        $builders = [];
        $allowed = [];
        foreach ($own as $node) {
            if ($node->kind !== NodeKind::MethodCall || isset($inner[$node->id])) {
                continue;
            }
            [$root, $links] = self::unwind($source, $node);
            $rootVariable = self::directVariable($source, $root);
            $name = $assignedChain[$node->id] ?? ($rootVariable !== null && isset($variables[$rootVariable]) ? $rootVariable : null);
            if ($name !== null) {
                $key = '$' . $name;
                $entity = $variables[$name];
                $allowed[$name] = ($allowed[$name] ?? 0) + ($rootVariable !== null ? 1 : 0);
            } else {
                $key = '#' . $node->id;
                $entity = $root->kind === NodeKind::StaticMethodCall ? self::builderEntity($source, $root, $classes) : null;
            }
            if ($entity === null) {
                continue;
            }
            $builders[$key]['entity'] = $entity;
            $builders[$key]['links'] = array_merge($builders[$key]['links'] ?? [], $links);
            $builders[$key]['escapes'] = false;
        }

        foreach ($variables as $name => $entity) {
            $key = '$' . $name;
            if (isset($builders[$key])) {
                // The assignment target is the one occurrence besides the chain roots.
                $builders[$key]['escapes'] = isset($captured[$name])
                    || ($occurrences[$name] ?? 0) > ($allowed[$name] ?? 0) + 1;
            }
        }

        return array_values($builders);
    }

    /** The lower-case method name of a link, as written. */
    public static function methodName(SourceFile $source, Node $link): ?string
    {
        return CallExpression::fromNode($source, $link)->getName($source);
    }

    /** The entity of `\Civi\Api4\X::action()` when the action's clauses name X's fields. */
    private static function builderEntity(SourceFile $source, Node $call, ClassLookup $classes): ?string
    {
        $expression = CallExpression::fromNode($source, $call);
        $action = $expression->getName($source);
        if ($action === null || !Api4Contract::readsEntityFields($action)) {
            return null;
        }

        return Api4Calls::entityOfClassReference($source, $expression->callee, $classes);
    }

    /**
     * The innermost expression of a method chain and its links, inner first.
     *
     * @return array{Node, list<Node>}
     */
    private static function unwind(SourceFile $source, Node $node): array
    {
        $links = [];
        $node = self::unwrap($source, $node);
        while ($node->kind === NodeKind::MethodCall) {
            array_unshift($links, $node);
            $node = self::unwrap($source, $source->getChildren($node)[0]);
        }

        return [$node, $links];
    }

    /** Strips the Expression and Call wrappers around an expression. */
    private static function unwrap(SourceFile $source, Node $node): Node
    {
        while (in_array($node->kind, [NodeKind::Expression, NodeKind::Call, NodeKind::Variable], true)) {
            $next = $source->getChildren($node)[0] ?? null;
            if ($next === null) {
                break;
            }
            $node = $next;
        }

        return $node;
    }

    /** The name of a plain `$name` expression, or null. */
    private static function directVariable(SourceFile $source, Node $node): ?string
    {
        $node = self::unwrap($source, $node);

        return $node->kind === NodeKind::DirectVariable ? self::variableName($source, $node) : null;
    }

    private static function variableName(SourceFile $source, Node $variable): string
    {
        return substr($source->getText($variable), 1);
    }

    /** @return list<string> */
    private static function variablesIn(SourceFile $source, Node $node): array
    {
        $nodes = $node->kind === NodeKind::DirectVariable ? [$node] : $source->getDescendants($node, NodeKind::DirectVariable);

        return array_map(static fn (Node $variable): string => self::variableName($source, $variable), $nodes);
    }

    /** Parameters and closure `use` variables: bound before the body runs. */
    private static function boundOutside(SourceFile $source, Node $functionLike): array
    {
        $names = [];
        foreach ($source->getChildren($functionLike) as $child) {
            if ($child->kind === NodeKind::FunctionLikeParameterList || $child->kind === NodeKind::ClosureUseClause) {
                foreach ($source->getChildren($child) as $item) {
                    $variable = $source->getFirstDescendant($item, NodeKind::DirectVariable);
                    if ($variable !== null) {
                        $names[] = self::variableName($source, $variable);
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Variables a node writes other than by a plain `$x = ...`.
     *
     * @return list<string>
     */
    private static function otherWrites(SourceFile $source, Node $node): array
    {
        return match ($node->kind) {
            NodeKind::ForeachTarget, NodeKind::Global, NodeKind::Static, NodeKind::Unset => self::variablesIn($source, $node),
            NodeKind::TryCatchClause => array_merge(...array_map(
                static fn (Node $child): array => $child->kind === NodeKind::DirectVariable ? self::variablesIn($source, $child) : [],
                $source->getChildren($node),
            )),
            default => [],
        };
    }

    /**
     * Every node of the body outside nested functions and classes; the
     * variable names those nested scopes mention go to $captured.
     *
     * @param list<Node>          $own
     * @param array<string, true> $captured
     */
    private static function ownNodes(SourceFile $source, Node $node, array &$own, array &$captured): void
    {
        if (in_array($node->kind, self::FUNCTION_LIKES, true) || in_array($node->kind, self::CLASS_LIKES, true)) {
            foreach (self::variablesIn($source, $node) as $name) {
                $captured[$name] = true;
            }
            return;
        }
        $own[] = $node;
        foreach ($source->getChildren($node) as $child) {
            self::ownNodes($source, $child, $own, $captured);
        }
    }
}
