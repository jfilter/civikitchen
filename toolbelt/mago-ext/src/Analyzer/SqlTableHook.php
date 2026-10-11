<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Options;
use CiviKitchen\Mago\Workspace;
use CiviKitchen\PHPStan\SqlSchema;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A literal SQL table name that neither CiviCRM core nor the extension's own
 * schema declares; `--sql-known-tables` lists further ones.
 */
final class SqlTableHook implements NodeAnalysisHook
{
    private ?SqlSchema $schema = null;

    public function __construct(private readonly Options $options)
    {
    }

    public function getTargets(): array
    {
        return [NodeKind::StaticMethodCall, NodeKind::MethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $this->schema ??= new SqlSchema(Workspace::root(), $this->options->list('sql-known-tables') ?? []);
        $messages = $context->node->kind === NodeKind::StaticMethodCall
            ? $this->staticCall($context->source, $context->node)
            : $this->methodCall($context->source, $context->node);
        foreach ($messages as $message) {
            $context->report(Level::Error, 'sql-unknown-table', Issue::new($message, $context->node->span));
        }
    }

    /** @return list<string> */
    private function staticCall(SourceFile $source, Node $call): array
    {
        $class = ltrim(SqlNodes::className($source, $call) ?? '', '\\');
        $method = SqlNodes::name($source, $call) ?? '';
        // executeQuery($query, ...), singleValueQuery($query, ...), executeUnbufferedQuery($query, ...)
        if (SqlNodes::isDatabaseCall($source, $call)) {
            $literal = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'query'));

            return $literal === null ? [] : $this->schema->sqlMessages($literal, sprintf('%s::%s()', $class, $method));
        }
        // CRM_Utils_SQL_Select::from($from, $options = [])
        if (strcasecmp($class, 'CRM_Utils_SQL_Select') === 0 && strtolower($method) === 'from') {
            $literal = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'from'));

            return $literal === null ? [] : $this->schema->tableClauseMessages($literal, 'CRM_Utils_SQL_Select::from()');
        }

        return [];
    }

    /** @return list<string> */
    private function methodCall(SourceFile $source, Node $call): array
    {
        $method = strtolower(SqlNodes::name($source, $call) ?? '');
        // CRM_Core_DAO::query($query, $i18nRewrite = TRUE)
        if (SqlNodes::isDatabaseCall($source, $call)) {
            $literal = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'query'));

            return $literal === null ? [] : $this->schema->sqlMessages($literal, sprintf('->%s()', $method));
        }
        // ->from($from, $options = []); ->join($name, $exprs, $args = NULL)
        if ($method === 'from') {
            $literal = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'from'));

            return $literal === null ? [] : $this->schema->tableClauseMessages($literal, '->from()');
        }
        if ($method === 'join') {
            $literal = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 1, 'exprs'));

            return $literal === null ? [] : $this->schema->sqlMessages($literal, '->join()');
        }

        return [];
    }
}
