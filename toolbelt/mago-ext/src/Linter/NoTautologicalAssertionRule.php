<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Support\Syntax;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Bans assertions whose outcome literals fix: assertTrue(TRUE) and kin,
 * assertNull(NULL), assertEmpty([]), assertSame/assertEquals on equal scalars.
 */
final class NoTautologicalAssertionRule implements Rule
{
    /** Boolean assertion (lower case) => the value that makes it a tautology. */
    private const BOOLEAN_ASSERTIONS = [
        'asserttrue' => true,
        'assertnottrue' => false,
        'assertfalse' => false,
        'assertnotfalse' => true,
    ];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/no-tautological-assertion',
            name: 'No tautological assertion',
            description: 'An assertion must depend on the code under test.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::MethodCall, NodeKind::NullSafeMethodCall, NodeKind::StaticMethodCall],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $selector = $source->getChildren($context->node)[1] ?? null;
        $member = $selector === null ? null : $source->getChildren($selector)[0] ?? null;
        if ($member?->kind !== NodeKind::LocalIdentifier) {
            return;
        }
        $name = $source->getText($member);
        if (self::isTautology($source, Syntax::arguments($source, $context->node), strtolower($name))) {
            $context->report(Issue::new(
                "Tautological assertion {$name}() passes regardless of the code under test; "
                . 'use $this->expectNotToPerformAssertions() or assert the postcondition',
                $member->span,
            ));
        }
    }

    /** @param list<array{name: ?string, value: ?Node, spread: bool, node: Node}> $arguments */
    private static function isTautology(SourceFile $source, array $arguments, string $name): bool
    {
        $value = static fn (int $position, string $parameter): ?Node => ($argument = Syntax::parameter($arguments, $position, $parameter)) === null
            || $argument['spread'] ? null : $argument['value'];
        if (isset(self::BOOLEAN_ASSERTIONS[$name])) {
            $condition = $value(1, 'condition');

            return $condition !== null && Syntax::booleanValue($source, $condition) === self::BOOLEAN_ASSERTIONS[$name];
        }
        if ($name === 'assertnull') {
            $actual = $value(1, 'actual');

            return $actual !== null && strtolower(Syntax::keywordLiteral($source, $actual) ?? '') === 'null';
        }
        if ($name === 'assertempty') {
            $actual = $value(1, 'actual');

            return $actual !== null && in_array($actual->kind, [NodeKind::Array, NodeKind::LegacyArray], true)
                && $source->getChildren($actual) === [];
        }
        if ($name === 'assertsame' || $name === 'assertequals') {
            $expected = $value(1, 'expected');
            $actual = $value(2, 'actual');
            $left = $expected === null ? null : self::scalarLiteral($source, $expected);

            return $left !== null && $actual !== null && $left === self::scalarLiteral($source, $actual);
        }

        return false;
    }

    /** A normalized spelling of a scalar literal, optionally behind one `-`; else null. */
    private static function scalarLiteral(SourceFile $source, Node $node): ?string
    {
        $sign = '';
        if ($node->kind === NodeKind::UnaryPrefix && Syntax::prefixOperator($source, $node) === '-') {
            $sign = '-';
            $node = Syntax::unwrap($source, $source->getChildren($node)[1]);
            if ($node->kind !== NodeKind::Literal) {
                return null;
            }
        }
        $keyword = Syntax::keywordLiteral($source, $node);
        if ($keyword !== null) {
            return $sign . strtolower($keyword);
        }
        $literal = $node->kind === NodeKind::Literal ? $source->getChildren($node)[0] ?? null : null;
        if (in_array($literal?->kind, [NodeKind::LiteralInteger, NodeKind::LiteralFloat], true)) {
            return $sign . $source->getText($literal);
        }
        $string = Syntax::stringLiteral($source, $node);

        return $string === null ? null : $sign . $string;
    }
}
