<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\DatabaseCatch;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A `try` that reaches the database directly and a `catch` that neither
 * rethrows nor logs at error level: the failure becomes a substituted value.
 */
final class NoSilentDatabaseCatchHook implements NodeAnalysisHook
{
    private const VARIABLES = [NodeKind::DirectVariable, NodeKind::IndirectVariable, NodeKind::NestedVariable];

    public function getTargets(): array
    {
        return [NodeKind::Try];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $children = $source->getChildren($context->node);
        $block = $children[1] ?? null;
        if ($block === null || !$this->containsDatabaseCall($context, $block)) {
            return;
        }
        foreach ($children as $catch) {
            if ($catch->kind !== NodeKind::TryCatchClause) {
                continue;
            }
            $body = $source->getFirstDescendant($catch, NodeKind::Block);
            if ($body === null || self::handles($source, $body)) {
                continue;
            }
            $context->report(Level::Error, 'db-silent-catch', Issue::new(DatabaseCatch::message(self::isEmpty($source, $body)), $catch->span));
        }
    }

    private function containsDatabaseCall(NodeAnalysisContext $context, Node $block): bool
    {
        foreach ($context->source->getDescendants($block) as $node) {
            if (SqlNodes::isDatabaseCall($context->source, $node, $context->analysis, $context->codebase)) {
                return true;
            }
        }

        return false;
    }

    /** No statement and no comment between the braces. */
    private static function isEmpty(SourceFile $source, Node $body): bool
    {
        return $source->getChildren($body) === [] && trim(substr($source->getText($body), 1, -1)) === '';
    }

    /** Does the catch body make the failure visible? */
    private static function handles(SourceFile $source, Node $body): bool
    {
        foreach ($source->getDescendants($body) as $node) {
            if ($node->kind === NodeKind::Throw || self::isLoudLog($source, $node)) {
                return true;
            }
        }

        return false;
    }

    /** \Civi::log()->error(...), ->log(LogLevel::ERROR, ...) and the levels above. */
    private static function isLoudLog(SourceFile $source, Node $node): bool
    {
        if ($node->kind !== NodeKind::MethodCall && $node->kind !== NodeKind::NullSafeMethodCall) {
            return false;
        }
        $method = SqlNodes::name($source, $node);
        if ($method === null) {
            return false;
        }
        $method = strtolower($method);
        $level = $method === 'log' ? self::logLevel($source, SqlNodes::argument($source, $node, 0, 'level')) : $method;
        if (!in_array($level, DatabaseCatch::LOUD_LEVELS, true)) {
            return false;
        }
        // The receiver only has to be something that produces a logger.
        $receiver = SqlNodes::receiver($source, $node);
        if ($receiver->kind === NodeKind::StaticMethodCall) {
            return strtolower((string) SqlNodes::name($source, $receiver)) === 'log';
        }

        return in_array($receiver->kind, [...self::VARIABLES, NodeKind::PropertyAccess, NodeKind::MethodCall], true);
    }

    /** The lowercased level of `'error'` or `LogLevel::ERROR`; null when unreadable. */
    private static function logLevel(SourceFile $source, ?Node $level): ?string
    {
        if ($level === null) {
            return null;
        }
        $level = SqlNodes::unwrap($source, $level);
        if ($level->kind === NodeKind::ClassConstantAccess) {
            [$class, $selector] = $source->getChildren($level);
            $class = SqlNodes::unwrap($source, $class);
            $constant = $source->getChildren($selector)[0] ?? null;
            if ($class->kind !== NodeKind::Identifier || $constant?->kind !== NodeKind::LocalIdentifier) {
                return null;
            }
            $name = $source->getResolvedName($class)?->name ?? $source->getText($class);
            $last = substr((string) strrchr('\\' . $name, '\\'), 1);

            return strcasecmp($last, 'LogLevel') === 0 ? strtolower($source->getText($constant)) : null;
        }
        $literal = SqlNodes::literalString($source, $level);

        return $literal === null ? null : strtolower($literal);
    }
}
