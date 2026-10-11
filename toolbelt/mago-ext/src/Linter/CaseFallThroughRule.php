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
use Mago\Sdk\Syntax\TriviaKind;

/**
 * A non-empty `case` that runs into the next label without a statement that leaves it or a comment before
 * that label; any comment marks the fall-through as meant, and `goto` counts as leaving wherever its label is.
 * Ported from amateescu/mago-drupal's drupal/case-fall-through (MIT, LICENSE-mago-drupal.txt).
 */
final class CaseFallThroughRule implements Rule
{
    private const LEAVE = [NodeKind::Return, NodeKind::Goto, NodeKind::Throw, NodeKind::ExitConstruct, NodeKind::DieConstruct];
    private const LOOP_EXITS = [NodeKind::Break, NodeKind::Continue];
    private const WRAPPERS = [NodeKind::ExpressionStatement, NodeKind::Expression, NodeKind::Construct, NodeKind::Parenthesized];
    private const ELSE_CLAUSES = [NodeKind::IfStatementBodyElseClause, NodeKind::IfColonDelimitedBodyElseClause];
    private const CLAUSES = [...self::ELSE_CLAUSES, NodeKind::IfStatementBodyElseIfClause, NodeKind::IfColonDelimitedBodyElseIfClause];
    /** Tool directives are not a note to the reader. */
    private const DIRECTIVE = '/^[\/#* \t]*(?:@mago-|phpcs:|@codingStandardsIgnore|@phpstan-|@psalm-)/';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/case-fall-through',
            name: 'Case fall through',
            description: 'A non-empty switch case ends with break, return, throw, continue or exit, or a comment before the next case marks the fall-through.',
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
            $context->report(Issue::new('End the case with break, or put a comment before the next case to mark the fall-through as meant.', ($source->getChildren($label)[0] ?? $label)->span));
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
            if ($trivia->span->start >= $start && $trivia->span->end <= $end
                && ($trivia->kind === TriviaKind::DocBlockComment || preg_match(self::DIRECTIVE, $source->getText($trivia->span)) !== 1)) {
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
            if (in_array($inner->kind, [...self::LEAVE, ...self::LOOP_EXITS], true) || self::matchThrows($source, $inner)
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
            NodeKind::Match => self::matchThrows($source, $inner),
            default => in_array($inner->kind, self::LEAVE, true),
        };
    }

    /** A match whose every arm throws or exits; one without a default throws when nothing matches. */
    private static function matchThrows(SourceFile $source, Node $match): bool
    {
        if ($match->kind !== NodeKind::Match) {
            return false;
        }
        $arms = 0;
        foreach ($source->getChildren($match) as $arm) {
            $variant = $arm->kind === NodeKind::MatchArm ? $source->getChildren($arm)[0] ?? null : null;
            $parts = $variant === null ? [] : $source->getChildren($variant);
            $result = end($parts);
            if ($result === false) {
                continue;
            }
            $arms++;
            if (!in_array(self::inner($source, $result)->kind, self::LEAVE, true)) {
                return false;
            }
        }

        return $arms > 0;
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

    /** A `finally` that leaves decides; otherwise the `try` block and every `catch` must end. */
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
            $statements = self::statements($source, $block);
            if ($child->kind === NodeKind::TryFinallyClause) {
                // PHP rejects break and continue out of a finally, so only a leaving statement can end it.
                if (self::ends($source, $statements, $depth)) {
                    return true;
                }
                continue;
            }
            $ends = $ends && self::ends($source, $statements, $depth);
        }

        return $ends;
    }

    /**
     * A nested switch with a `default` that leaves from every label: each case
     * ends, or runs on, without a break of its own, into one that does.
     */
    private static function switchEnds(SourceFile $source, Node $switch, int $depth): bool
    {
        $labels = self::labels($source, $switch);
        $defaults = array_filter($labels, static fn (Node $label): bool => $label->kind === NodeKind::SwitchDefaultCase);
        $rest = false;
        foreach (array_reverse($labels) as $label) {
            $statements = self::statements($source, $label);
            $last = end($statements);
            $runsOn = $last === false || !in_array(self::inner($source, $last)->kind, self::LOOP_EXITS, true);
            $rest = self::ends($source, $statements, $depth + 1) || ($runsOn && $rest);
            if (!$rest) {
                return false;
            }
        }

        return $defaults !== [] && $labels !== [];
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
