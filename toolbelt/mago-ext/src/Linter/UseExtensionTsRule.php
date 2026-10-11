<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Bare ts() resolves in core's translation domain, so an extension's own .po
 * files are never consulted. Flags calls of the global ts() and 'ts' as the
 * whole callback argument of the higher-order functions in CALLBACK_POSITIONS.
 */
final class UseExtensionTsRule implements Rule
{
    /** Higher-order function (lower case) => 1-based position of its `callback:` parameter. */
    private const CALLBACK_POSITIONS = [
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

    private const MESSAGE = 'Bare ts() resolves in core\'s translation domain — use E::ts() (the civix '
        . 'CRM_<Ext>_ExtensionUtil helper) so the extension\'s own translations apply';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/use-extension-ts',
            name: 'Use E::ts()',
            description: 'Extension code translates through E::ts(), not core\'s global ts().',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::FunctionCall, NodeKind::FunctionPartialApplication],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $callee = self::unwrap($source, $source->getChildren($context->node)[0]);
        $name = self::globalFunctionName($source, $callee);
        if ($name === 'ts') {
            $context->report(Issue::new(self::MESSAGE, $callee->span));
            return;
        }
        if ($name === null || !isset(self::CALLBACK_POSITIONS[$name]) || $context->node->kind !== NodeKind::FunctionCall) {
            return;
        }
        $callback = self::callbackArgument(CallExpression::fromNode($source, $context->node), self::CALLBACK_POSITIONS[$name]);
        if ($callback !== null && self::isLiteralString($source, $callback, 'ts')) {
            $context->report(Issue::new(self::MESSAGE, $callback->span));
        }
    }

    /** The lower-case name of the global function a callee names: `name` or `\name`, never `Ns\name`. */
    private static function globalFunctionName(SourceFile $source, Node $callee): ?string
    {
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

    /** The value bound to `callback:` at $position, unless a spread makes the binding unknowable. */
    private static function callbackArgument(CallExpression $call, int $position): ?Node
    {
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return null;
            }
            if ($argument->name === null ? $argument->index === $position - 1 : $argument->name === 'callback') {
                return $argument->value;
            }
        }

        return null;
    }

    private static function isLiteralString(SourceFile $source, Node $node, string $value): bool
    {
        if ($node->kind !== NodeKind::Literal) {
            return false;
        }
        $literal = $source->getChildren($node)[0] ?? null;
        if ($literal?->kind !== NodeKind::LiteralString) {
            return false;
        }

        return strcasecmp(substr($source->getText($literal), 1, -1), $value) === 0;
    }

    private static function unwrap(SourceFile $source, Node $node): Node
    {
        while ($node->kind === NodeKind::Expression) {
            $node = $source->getChildren($node)[0];
        }

        return $node;
    }
}
