<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Whether a getter reads `$this-><name>` only where an uninitialized property
 * cannot fatal: inside `??`, isset(), empty(), or a branch, operand or match arm where it is set.
 */
final class GuardedGetter
{
    private const WRAPPERS = [NodeKind::Expression, NodeKind::Access, NodeKind::Construct, NodeKind::Parenthesized, NodeKind::Statement];

    private function __construct(private readonly SourceFile $source, private readonly string $name)
    {
    }

    public static function guards(SourceFile $source, Node $method, string $name): bool
    {
        $block = $source->getFirstDescendant($method, NodeKind::MethodBody);
        $block = $block === null ? null : $source->getFirstDescendant($block, NodeKind::Block);
        if ($block === null) {
            return false;
        }
        $getter = new self($source, $name);
        $safe = [];
        foreach ($source->getDescendants($block) as $guard) {
            foreach ($getter->guardedLinks($guard) as $link) {
                if ($getter->isRead($link)) {
                    $safe[$link->id] = true;
                }
            }
            foreach ($getter->reads($getter->covered($guard)) as $id => $_) {
                $safe[$id] = true;
            }
        }
        $unsafe = array_diff_key($getter->reads($getter->untilInitialised($getter->statements($block))), $safe);

        return $getter->reads([$block]) !== [] && $unsafe === [];
    }

    /**
     * The operand isset(), empty(), `??` or an assignment target reads without the uninitialized error.
     *
     * @return list<Node>
     */
    private function guardedLinks(Node $guard): array
    {
        $children = $this->source->getChildren($guard);

        return match ($guard->kind) {
            NodeKind::IssetConstruct => array_merge(...array_map($this->fetchChain(...), $this->expressions($guard))),
            NodeKind::EmptyConstruct => $this->fetchChain($this->expressions($guard)[0]),
            NodeKind::Binary => $this->operator($guard) === '??' ? $this->fetchChain($children[0]) : [],
            // A write creates arrays on the way but not objects.
            NodeKind::Assignment => in_array($this->operator($guard), ['=', '??='], true) ? $this->fetchChain($children[0], arraysOnly: true) : [],
            default => [],
        };
    }

    /** @return list<Node> the subtrees in which a read is known to find the property set */
    private function covered(Node $guard): array
    {
        $parts = $this->expressions($guard);

        return match ($guard->kind) {
            NodeKind::Conditional => match ($this->presence($parts[0])) {
                true => count($parts) === 3 ? [$parts[1]] : [],
                false => [$parts[count($parts) - 1]],
                null => [],
            },
            NodeKind::If => $this->presence($parts[0]) === true ? $this->ifBranches($guard)[0][1] : $this->branchesAfterUnset($guard),
            NodeKind::IfStatementBodyElseIfClause, NodeKind::IfColonDelimitedBodyElseIfClause => $this->presence($parts[0]) === true ? $this->branchStatements($guard) : [],
            NodeKind::Binary => match (true) {
                $this->isConjunction($guard) && $this->presence($parts[0]) === true,
                $this->isDisjunction($guard) && $this->presence($parts[0]) === false => [$parts[1]],
                default => [],
            },
            NodeKind::Match => $this->coveredArms($guard),
            default => [],
        };
    }

    /** In `match (true)`: arms whose conditions all find it set, and every arm after one that finds it unset. */
    private function coveredArms(Node $match): array
    {
        $subject = $this->strip($this->expressions($match)[0]);
        if (!in_array($subject->kind, [NodeKind::Literal, NodeKind::ConstantAccess], true) || $this->constant($subject) !== 'true') {
            return [];
        }
        $arms = array_map(fn (Node $arm): Node => $this->source->getChildren($arm)[0], array_values(array_filter(
            $this->source->getChildren($match),
            static fn (Node $child): bool => $child->kind === NodeKind::MatchArm,
        )));
        $covered = [];
        foreach ($arms as $i => $arm) {
            $conditions = $arm->kind === NodeKind::MatchExpressionArm ? array_slice($this->expressions($arm), 0, -1) : [];
            $presences = array_map($this->presence(...), $conditions);
            if (in_array(false, $presences, true)) {
                return [...$covered, ...array_slice($arms, $i + 1)];
            }
            if ($presences !== [] && !in_array(null, $presences, true)) {
                $covered[] = $this->expressions($arm)[count($conditions)];
            }
        }

        return $covered;
    }

    /** The elseif and else branches after a condition whose being false means the property is set. */
    private function branchesAfterUnset(Node $if): array
    {
        $branches = $this->ifBranches($if);
        foreach ($branches as $i => [$condition]) {
            if ($condition !== null && $this->presence($condition) === false) {
                return array_map(static fn (array $branch): Node => $branch[2], array_slice($branches, $i + 1));
            }
        }

        return [];
    }

    /**
     * The if, its elseifs and its else: condition (null for else), statements, branch node.
     *
     * @return list<array{?Node, list<Node>, Node}>
     */
    private function ifBranches(Node $if): array
    {
        [, $condition, $body] = $this->source->getChildren($if);
        $body = $this->source->getChildren($body)[0];
        $branches = [[$condition, $this->branchStatements($body), $body]];
        foreach ($this->source->getChildren($body) as $clause) {
            if (in_array($clause->kind, [NodeKind::IfStatementBodyElseIfClause, NodeKind::IfColonDelimitedBodyElseIfClause], true)) {
                $branches[] = [$this->expressions($clause)[0], $this->branchStatements($clause), $clause];
            } elseif (in_array($clause->kind, [NodeKind::IfStatementBodyElseClause, NodeKind::IfColonDelimitedBodyElseClause], true)) {
                $branches[] = [null, $this->branchStatements($clause), $clause];
            }
        }

        return $branches;
    }

    /** @return list<Node> a branch's own statements, a braced block flattened */
    private function branchStatements(Node $branch): array
    {
        $statements = [];
        foreach ($this->source->getChildren($branch) as $child) {
            if ($child->kind === NodeKind::Statement) {
                $inner = $this->strip($child);
                array_push($statements, ...($inner->kind === NodeKind::Block ? $this->statements($inner) : [$inner]));
            }
        }

        return $statements;
    }

    /** @return list<Node> */
    private function statements(Node $block): array
    {
        return array_map($this->strip(...), $this->source->getChildren($block));
    }

    /**
     * The statements up to the one that initialises the property; inside an initialising if, each branch up to it.
     *
     * @param  list<Node> $statements
     * @return list<Node>
     */
    private function untilInitialised(array $statements): array
    {
        $nodes = [];
        foreach ($statements as $statement) {
            if (!$this->initialises($statement)) {
                $nodes[] = $statement;
                continue;
            }
            if ($statement->kind !== NodeKind::If) {
                return [...$nodes, $statement];
            }
            foreach ($this->ifBranches($statement) as [$condition, $branch]) {
                array_push($nodes, ...($condition === null ? [] : [$condition]), ...$this->untilInitialised($branch));
            }

            return $nodes;
        }

        return $nodes;
    }

    /** An assignment to the property or its offset, or an if whose branches initialise or find it set. */
    private function initialises(Node $statement): bool
    {
        if ($statement->kind === NodeKind::ExpressionStatement) {
            $assignment = $this->strip($this->source->getChildren($statement)[0]);

            return $assignment->kind === NodeKind::Assignment && in_array($this->operator($assignment), ['=', '??='], true)
                && array_filter($this->fetchChain($this->source->getChildren($assignment)[0], arraysOnly: true), $this->isRead(...)) !== [];
        }
        if ($statement->kind !== NodeKind::If) {
            return false;
        }
        foreach ($this->ifBranches($statement) as [$condition, $branch]) {
            if ($condition === null) {
                return $this->blockInitialises($branch);
            }
            $presence = $this->presence($condition);
            if ($presence !== true && !$this->blockInitialises($branch)) {
                return false;
            }
            if ($presence === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A branch that assigns the property or ends in return or throw.
     *
     * @param list<Node> $statements
     */
    private function blockInitialises(array $statements): bool
    {
        $last = end($statements);
        if ($last !== false && ($last->kind === NodeKind::Return || ($last->kind === NodeKind::ExpressionStatement
            && $this->strip($this->source->getChildren($last)[0])->kind === NodeKind::Throw))) {
            return true;
        }

        return array_filter($statements, $this->initialises(...)) !== [];
    }

    /**
     * TRUE when the expression being true means the property is set (`isset($this->x)`),
     * FALSE when its being false does (`empty($this->x)`), else NULL; `!` swaps them.
     */
    private function presence(Node $expression): ?bool
    {
        $expression = $this->strip($expression);
        $parts = $this->expressions($expression);
        if ($this->isConjunction($expression) || $this->isDisjunction($expression)) {
            $decisive = $this->isConjunction($expression);

            return in_array($decisive, [$this->presence($parts[0]), $this->presence($parts[1])], true) ? $decisive : null;
        }
        if ($expression->kind === NodeKind::UnaryPrefix && $this->operator($expression) === '!') {
            $inner = $this->presence($parts[0]);

            return $inner === null ? null : !$inner;
        }
        // `isset($this->x) === false`, `empty($this->x) != true`.
        $operator = $expression->kind === NodeKind::Binary ? strtolower($this->operator($expression)) : '';
        if (in_array($operator, ['===', '!==', '==', '!=', '<>'], true)) {
            $right = $this->strip($parts[1]);
            $constant = in_array($right->kind, [NodeKind::Literal, NodeKind::ConstantAccess], true) ? $this->constant($right) : null;
            $inner = in_array($constant, ['true', 'false'], true) ? $this->presence($parts[0]) : null;
            $flip = ($constant === 'false') !== in_array($operator, ['!==', '!=', '<>'], true);

            return $inner === null ? null : $inner !== $flip;
        }
        // A set offset or property of the property means the property is set too.
        $reads = fn (Node $operand): bool => array_filter($this->fetchChain($operand), $this->isRead(...)) !== [];

        return match (true) {
            $expression->kind === NodeKind::IssetConstruct && array_filter($parts, $reads) !== [] => true,
            $expression->kind === NodeKind::EmptyConstruct && $reads($parts[0]) => false,
            default => null,
        };
    }

    /**
     * The expression and the bases it is fetched from: `$this->x` in `$this->x['k']->y`, not the offset.
     *
     * @return non-empty-list<Node>
     */
    private function fetchChain(Node $expression, bool $arraysOnly = false): array
    {
        $kinds = $arraysOnly ? [NodeKind::ArrayAccess, NodeKind::ArrayAppend]
            : [NodeKind::ArrayAccess, NodeKind::ArrayAppend, NodeKind::PropertyAccess, NodeKind::NullSafePropertyAccess];
        $chain = [$expression = $this->strip($expression)];
        while (in_array($expression->kind, $kinds, true)) {
            $chain[] = $expression = $this->strip($this->source->getChildren($expression)[0]);
        }

        return $chain;
    }

    private function isRead(Node $node): bool
    {
        if ($node->kind !== NodeKind::PropertyAccess) {
            return false;
        }
        [$object, $selector] = $this->source->getChildren($node);
        $identifier = $this->source->getChildren($selector)[0] ?? null;

        return $this->source->getText($this->strip($object)) === '$this'
            && $identifier?->kind === NodeKind::LocalIdentifier && $this->source->getText($identifier) === $this->name;
    }

    /**
     * @param  list<Node>       $nodes
     * @return array<int, true> the reads in and under the nodes, by id
     */
    private function reads(array $nodes): array
    {
        $reads = [];
        foreach ($nodes as $node) {
            foreach ([$node, ...$this->source->getDescendants($node, NodeKind::PropertyAccess)] as $candidate) {
                if ($this->isRead($candidate)) {
                    $reads[$candidate->id] = true;
                }
            }
        }

        return $reads;
    }

    private function isConjunction(Node $node): bool
    {
        return $node->kind === NodeKind::Binary && in_array(strtolower($this->operator($node)), ['&&', 'and'], true);
    }

    private function isDisjunction(Node $node): bool
    {
        return $node->kind === NodeKind::Binary && in_array(strtolower($this->operator($node)), ['||', 'or'], true);
    }

    private function operator(Node $node): string
    {
        foreach ($this->source->getChildren($node) as $child) {
            if (in_array($child->kind, [NodeKind::BinaryOperator, NodeKind::AssignmentOperator, NodeKind::UnaryPrefixOperator], true)) {
                return $this->source->getText($child);
            }
        }

        return '';
    }

    private function constant(Node $node): string
    {
        return strtolower(ltrim($this->source->getText($node), '\\'));
    }

    /** @return list<Node> the expression children, wrappers kept */
    private function expressions(Node $node): array
    {
        return array_values(array_filter($this->source->getChildren($node), static fn (Node $child): bool => $child->kind === NodeKind::Expression));
    }

    private function strip(Node $node): Node
    {
        while (in_array($node->kind, self::WRAPPERS, true)) {
            $inner = $this->source->getChildren($node);
            if (count($inner) !== 1) {
                break;
            }
            $node = $inner[0];
        }

        return $node;
    }
}
