<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A failed query that nobody ever hears about.
 *
 * When a `try` reaches the database directly and its `catch` neither
 * rethrows nor logs at error level, the failure becomes a substituted value:
 * an empty list where rows were expected, a zero where a sum was. The caller
 * cannot tell that apart from a legitimately empty result, so the bug
 * surfaces as wrong data days later instead of as an exception now. An
 * honest error beats a silent fallback.
 *
 * `\Civi::log()->debug()`/`info()` does not count: those levels are off on
 * every production site, so the branch is still silent where it matters.
 *
 * APIv4 calls are deliberately out of scope — they raise typed exceptions
 * that callers legitimately catch for control flow (an absent record, a
 * permission denial), and the catch-all here would drown that in noise.
 *
 * @implements Rule<Node\Stmt\TryCatch>
 */
final class NoSilentDatabaseCatchRule implements Rule
{
    /** Log levels that reach a production log. */
    private const LOUD_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    public function getNodeType(): string
    {
        return Node\Stmt\TryCatch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::containsDatabaseCall($node->stmts)) {
            return [];
        }

        $errors = [];
        foreach ($node->catches as $catch) {
            if (self::handles($catch)) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(
                $catch->stmts === []
                    ? 'Empty catch around a database call — the query can fail and nothing will say so. '
                        . 'Rethrow, or \\Civi::log()->error() with the context.'
                    : 'Catch around a database call neither rethrows nor logs at error level — a failed query '
                        . 'becomes a substituted value the caller cannot tell from real data. '
                        . 'Rethrow, or \\Civi::log()->error() with the context.',
            )->identifier('ck.db.silentCatch')->line($catch->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    private static function containsDatabaseCall(array $stmts): bool
    {
        return (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => Sql::isDatabaseCall($n)) !== null;
    }

    /** Does the catch body make the failure visible? */
    private static function handles(Node\Stmt\Catch_ $catch): bool
    {
        $found = (new NodeFinder())->findFirst(
            $catch->stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\Throw_
                || $n instanceof Node\Expr\Throw_
                || self::isLoudLog($n),
        );

        return $found !== null;
    }

    /** \Civi::log()->error(...) and the levels above it. */
    private static function isLoudLog(Node $node): bool
    {
        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier) {
            return false;
        }
        if (!in_array($node->name->toLowerString(), self::LOUD_LEVELS, true)) {
            return false;
        }
        // ->log(LogLevel::ERROR, …) is not spelled here; the receiver only
        // has to be something that produces a logger.
        $receiver = $node->var;
        if ($receiver instanceof Node\Expr\StaticCall) {
            return $receiver->name instanceof Node\Identifier && $receiver->name->toLowerString() === 'log';
        }

        return $receiver instanceof Node\Expr\Variable
            || $receiver instanceof Node\Expr\PropertyFetch
            || $receiver instanceof Node\Expr\MethodCall;
    }
}
