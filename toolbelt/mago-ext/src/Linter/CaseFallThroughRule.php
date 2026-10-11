<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A non-empty `case` that runs into the next label without a statement that leaves it or a comment saying so.
 * Ported from amateescu/mago-drupal's drupal/case-fall-through (MIT, LICENSE-mago-drupal.txt).
 */
final class CaseFallThroughRule implements Rule
{
    private const LEAVE = [NodeKind::Return, NodeKind::Goto, NodeKind::Throw, NodeKind::ExitConstruct, NodeKind::DieConstruct];
    private const LOOP_EXITS = [NodeKind::Break, NodeKind::Continue];
    private const WRAPPERS = [NodeKind::ExpressionStatement, NodeKind::Expression, NodeKind::Construct];
    private const ELSE_CLAUSES = [NodeKind::IfStatementBodyElseClause, NodeKind::IfColonDelimitedBodyElseClause];
    private const CLAUSES = [...self::ELSE_CLAUSES, NodeKind::IfStatementBodyElseIfClause, NodeKind::IfColonDelimitedBodyElseIfClause];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/case-fall-through',
            name: 'Case fall through',
            description: 'A non-empty switch case ends with break, return, throw, continue or exit, or a comment says it falls through.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Switch],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $labels = self::labels($source, $context->node);
        foreach ($labels as $index => $label) {
            $next = $labels[$index + 1] ?? null;
            $statements = self::statements($source, $label);
            $last = end($statements);
            // A default case and the last case have nothing to fall into that a reader would miss.
            if ($next === null || $last === false || $label->kind !== NodeKind::SwitchExpressionCase
                || self::hasComment($source, $last->span->end, $next->span->start) || self::leaves($source, $statements)) {
                continue;
            }
            $context->report(Issue::new('End the case with break, or add a comment that says it falls through.', ($source->getChildren($label)[0] ?? $label)->span));
        }
    }

    /** @return list<Node> the `case` and `default` labels of the switch itself, in source order */
    private static function labels(SourceFile $source, Node $switch): array
    {
        $children = $source->getChildren($switch);
        $body = $source->getChildren(end($children) ?: $switch)[0] ?? null;
        $labels = [];
        foreach ($body === null ? [] : $source->getChildren($body) as $case) {
            $label = $case->kind === NodeKind::SwitchCase ? $source->getChildren($case)[0] ?? null : null;
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /** @return list<Node> */
    private static function statements(SourceFile $source, Node $node): array
    {
        return array_values(array_filter($source->getChildren($node), static fn (Node $child): bool => $child->kind === NodeKind::Statement));
    }

    private static function hasComment(SourceFile $source, int $start, int $end): bool
    {
        foreach ($source->getTrivia() as $trivia) {
            if ($trivia->span->start >= $start && $trivia->span->end <= $end) {
                return true;
            }
        }

        return false;
    }

    private static function inner(SourceFile $source, Node $statement): Node
    {
        $inner = $source->getChildren($statement)[0] ?? $statement;
        while (in_array($inner->kind, self::WRAPPERS, true)) {
            $inner = $source->getChildren($inner)[0] ?? $statement;
        }

        return $inner;
    }

    /**
     * Some statement leaves the case outright (code after it is dead), or the last one does in every branch.
     *
     * @param list<Node> $statements
     */
    private static function leaves(SourceFile $source, array $statements): bool
    {
        foreach ($statements as $statement) {
            $inner = self::inner($source, $statement);
            if (in_array($inner->kind, [...self::LEAVE, ...self::LOOP_EXITS], true)
                || ($inner->kind === NodeKind::Block && self::leaves($source, self::statements($source, $inner)))) {
                return true;
            }
        }

        return self::ends($source, $statements, 0);
    }

    /**
     * The last statement always leaves; $depth counts the nested switches a one-level break only leaves.
     *
     * @param list<Node> $statements
     */
    private static function ends(SourceFile $source, array $statements, int $depth): bool
    {
        $last = end($statements);
        if ($last === false) {
            return false;
        }
        $inner = self::inner($source, $last);

        return match ($inner->kind) {
            NodeKind::Break, NodeKind::Continue => self::level($source, $inner) > $depth,
            NodeKind::Block => self::ends($source, self::statements($source, $inner), $depth),
            NodeKind::If => self::ifEnds($source, $inner, $depth),
            NodeKind::Try => self::tryEnds($source, $inner, $depth),
            NodeKind::Switch => self::switchEnds($source, $inner, $depth),
            default => in_array($inner->kind, self::LEAVE, true),
        };
    }

    private static function ifEnds(SourceFile $source, Node $if, int $depth): bool
    {
        $bodies = array_values(array_filter($source->getChildren($if), static fn (Node $child): bool => $child->kind === NodeKind::IfBody));
        $body = $bodies === [] ? null : $source->getChildren($bodies[0])[0] ?? null;
        if ($body === null) {
            return false;
        }
        $hasElse = false;
        $branches = [self::statements($source, $body)];
        foreach ($source->getChildren($body) as $clause) {
            if (in_array($clause->kind, self::CLAUSES, true)) {
                $hasElse = $hasElse || in_array($clause->kind, self::ELSE_CLAUSES, true);
                $branches[] = self::statements($source, $clause);
            }
        }
        foreach ($branches as $branch) {
            if (!self::ends($source, $branch, $depth)) {
                return false;
            }
        }

        return $hasElse;
    }

    /** A `finally` that ends, or a `try` block and every `catch` that end. */
    private static function tryEnds(SourceFile $source, Node $try, int $depth): bool
    {
        $ends = true;
        foreach ($source->getChildren($try) as $child) {
            $block = $child->kind === NodeKind::Block ? $child : null;
            foreach ($block === null ? $source->getChildren($child) : [] as $part) {
                $block = $part->kind === NodeKind::Block ? $part : $block;
            }
            if ($block === null) {
                continue;
            }
            $blockEnds = self::ends($source, self::statements($source, $block), $depth);
            if ($child->kind === NodeKind::TryFinallyClause && $blockEnds) {
                return true;
            }
            if ($child->kind !== NodeKind::TryFinallyClause) {
                $ends = $ends && $blockEnds;
            }
        }

        return $ends;
    }

    /** A nested switch with a `default`, whose non-empty cases all end, the last one included. */
    private static function switchEnds(SourceFile $source, Node $switch, int $depth): bool
    {
        $labels = self::labels($source, $switch);
        $statements = [];
        foreach ($labels as $label) {
            $statements = self::statements($source, $label);
            if ($statements !== [] && !self::ends($source, $statements, $depth + 1)) {
                return false;
            }
        }
        $defaults = array_filter($labels, static fn (Node $label): bool => $label->kind === NodeKind::SwitchDefaultCase);

        return $defaults !== [] && $statements !== [];
    }

    /** The number of levels a `break` or `continue` leaves. */
    private static function level(SourceFile $source, Node $statement): int
    {
        foreach ($source->getChildren($statement) as $child) {
            $text = trim($source->getText($child));
            if ($child->kind === NodeKind::Expression && ctype_digit($text)) {
                return (int) $text;
            }
        }

        return 1;
    }
}
