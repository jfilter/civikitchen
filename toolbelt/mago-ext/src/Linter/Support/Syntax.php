<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter\Support;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Call-site helpers for the token-level rules: argument lists of calls,
 * partial applications, instantiations and attributes, and literal checks.
 */
final class Syntax
{
    /** Higher-order function (lower case) => 1-based position of its `callback:` parameter. */
    public const CALLBACK_POSITIONS = [
        'array_map' => 1,
        'call_user_func' => 1,
        'call_user_func_array' => 1,
        'array_filter' => 2,
        'array_walk' => 2,
        'array_walk_recursive' => 2,
        'usort' => 2,
        'uasort' => 2,
        'uksort' => 2,
        'array_reduce' => 2,
    ];

    private function __construct()
    {
    }

    /**
     * The arguments of a call-like node in source order; `value` is null for a placeholder.
     *
     * @return list<array{name: ?string, value: ?Node, spread: bool, node: Node}>
     */
    public static function arguments(SourceFile $source, Node $callLike): array
    {
        $list = null;
        foreach ($source->getChildren($callLike) as $child) {
            if ($child->kind === NodeKind::ArgumentList || $child->kind === NodeKind::PartialArgumentList) {
                $list = $child;
            }
        }
        $arguments = [];
        foreach ($list === null ? [] : $source->getChildren($list) as $wrapper) {
            $variant = $source->getChildren($wrapper)[0] ?? null;
            if ($variant === null) {
                continue;
            }
            $parts = $source->getChildren($variant);
            $value = match ($variant->kind) {
                NodeKind::PositionalArgument => $parts[0] ?? null,
                NodeKind::NamedArgument => $parts[1] ?? null,
                default => null,
            };
            $arguments[] = [
                'name' => $variant->kind === NodeKind::NamedArgument || $variant->kind === NodeKind::NamedPlaceholderArgument
                    ? $source->getText($parts[0]) : null,
                'value' => $value === null ? null : self::unwrap($source, $value),
                'spread' => $variant->kind === NodeKind::PositionalArgument && str_starts_with($source->getText($variant), '...'),
                'node' => $wrapper,
            ];
        }

        return $arguments;
    }

    /**
     * The argument a parameter receives, as PHP binds it: the first
     * argument named $name, else the unnamed one at 1-based $position.
     *
     * @param list<array{name: ?string, value: ?Node, spread: bool, node: Node}> $arguments
     * @return ?array{name: ?string, value: ?Node, spread: bool, node: Node}
     */
    public static function parameter(array $arguments, int $position, string $name): ?array
    {
        foreach ($arguments as $argument) {
            if ($argument['name'] === $name) {
                return $argument;
            }
        }
        $argument = $arguments[$position - 1] ?? null;

        return $argument !== null && $argument['name'] === null ? $argument : null;
    }

    /** The argument list ends in a comma after its last argument (comments ignored). */
    public static function hasTrailingComma(SourceFile $source, Node $callLike): bool
    {
        foreach ($source->getChildren($callLike) as $list) {
            if ($list->kind !== NodeKind::ArgumentList && $list->kind !== NodeKind::PartialArgumentList) {
                continue;
            }
            $arguments = $source->getChildren($list);
            $last = end($arguments);
            if ($last === false) {
                return false;
            }
            $tail = substr($source->contents, $last->span->end, $list->span->end - $last->span->end);

            return str_contains((string) preg_replace('~/\*.*?\*/|//[^\n]*|#[^\n]*~s', '', $tail), ',');
        }

        return false;
    }

    /** The lower-case name of the global function a callee names: `name` or `\name`, never `Ns\name`. */
    public static function globalFunctionName(SourceFile $source, Node $callee): ?string
    {
        $callee = self::unwrap($source, $callee);
        if ($callee->kind !== NodeKind::Identifier) {
            return null;
        }
        $identifier = $source->getChildren($callee)[0] ?? null;
        $text = $identifier === null ? '' : $source->getText($identifier);
        $name = match ($identifier?->kind) {
            NodeKind::LocalIdentifier => $text,
            NodeKind::FullyQualifiedIdentifier => substr($text, 1),
            default => null,
        };

        return $name === null || str_contains($name, '\\') ? null : strtolower($name);
    }

    /** The value of a TRUE/FALSE literal, `\`-qualified or not; null for anything else. */
    public static function booleanLiteral(SourceFile $source, Node $node): ?bool
    {
        $text = strtolower(self::keywordLiteral($source, $node) ?? '');

        return match ($text) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /** A boolean literal behind any number of `!`: its resulting value, else null. */
    public static function booleanValue(SourceFile $source, Node $node): ?bool
    {
        $negations = 0;
        $node = self::unwrap($source, $node);
        while ($node->kind === NodeKind::UnaryPrefix && self::prefixOperator($source, $node) === '!') {
            $negations++;
            $node = self::unwrap($source, $source->getChildren($node)[1]);
        }
        $value = self::booleanLiteral($source, $node);

        return $value === null ? null : $value !== ($negations % 2 === 1);
    }

    /** TRUE, FALSE or NULL as written without a leading `\`, from a keyword literal or `\TRUE`; else null. */
    public static function keywordLiteral(SourceFile $source, Node $node): ?string
    {
        $node = self::unwrap($source, $node);
        if ($node->kind === NodeKind::Literal && ($source->getChildren($node)[0] ?? null)?->kind === NodeKind::Keyword) {
            return $source->getText($node);
        }
        if ($node->kind !== NodeKind::ConstantAccess) {
            return null;
        }
        $identifier = $source->getChildren($source->getChildren($node)[0] ?? $node)[0] ?? null;
        if ($identifier?->kind !== NodeKind::FullyQualifiedIdentifier) {
            return null;
        }
        $name = substr($source->getText($identifier), 1);

        return in_array(strtolower($name), ['true', 'false', 'null'], true) ? $name : null;
    }

    /** The operator of a UnaryPrefix node. */
    public static function prefixOperator(SourceFile $source, Node $node): string
    {
        return $source->getText($source->getChildren($node)[0]);
    }

    /** A single-quoted or plain double-quoted string literal's text, quotes included; else null. */
    public static function stringLiteral(SourceFile $source, Node $node): ?string
    {
        $node = self::unwrap($source, $node);
        $literal = $node->kind === NodeKind::Literal ? $source->getChildren($node)[0] ?? null : null;
        if ($literal?->kind !== NodeKind::LiteralString) {
            return null;
        }
        $text = $source->getText($literal);

        return in_array($text[0] ?? '', ['\'', '"'], true) ? $text : null;
    }

    /** A string literal's text without its quotes. */
    public static function stripQuotes(string $text): string
    {
        return strlen($text) >= 2 && in_array($text[0], ['\'', '"'], true) && $text[-1] === $text[0] ? substr($text, 1, -1) : $text;
    }

    public static function unwrap(SourceFile $source, Node $node): Node
    {
        while ($node->kind === NodeKind::Expression) {
            $next = $source->getChildren($node)[0] ?? null;
            if ($next === null) {
                break;
            }
            $node = $next;
        }

        return $node;
    }
}
