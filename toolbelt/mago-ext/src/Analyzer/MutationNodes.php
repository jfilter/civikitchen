<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\Mutation;
use CiviKitchen\PHPStan\Sql;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Database writes in mago's syntax tree: the counterpart of Mutation::describe(),
 * with the same descriptions.
 */
final class MutationNodes
{
    private function __construct()
    {
    }

    /** A short description of the write, or null when this is not one. */
    public static function describe(SourceFile $source, Node $node): ?string
    {
        return match ($node->kind) {
            NodeKind::StaticMethodCall => self::describeStaticCall($source, $node),
            NodeKind::FunctionCall => self::describeFunctionCall($source, $node),
            NodeKind::MethodCall => self::describeMethodCall($source, $node),
            default => null,
        };
    }

    private static function describeStaticCall(SourceFile $source, Node $call): ?string
    {
        $class = ltrim(SqlNodes::className($source, $call) ?? '', '\\');
        $method = SqlNodes::name($source, $call) ?? '';
        if ($method === '') {
            return null;
        }
        if (stripos($class, 'Civi\\Api4\\') === 0 && Mutation::isApi4WriteAction($method)) {
            return sprintf('%s::%s()', substr($class, strlen('Civi\\Api4\\')), $method);
        }
        if (!Sql::isDaoClass($class)) {
            return null;
        }
        if (in_array(strtolower($method), Mutation::DAO_WRITE_METHODS, true)) {
            return sprintf('%s::%s()', $class, $method);
        }
        $sql = self::sqlArgument($source, $call);

        return $sql === null ? null : sprintf('%s in %s::%s()', $sql, $class, $method);
    }

    private static function describeFunctionCall(SourceFile $source, Node $call): ?string
    {
        $function = strtolower(ltrim(SqlNodes::name($source, $call) ?? '', '\\'));
        if (!in_array($function, ['civicrm_api4', 'civicrm_api3', 'civicrm_api'], true)) {
            return null;
        }
        $entity = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'entity'));
        $action = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 1, 'action'));
        if ($entity === null || $action === null) {
            return null;
        }
        $writes = $function === 'civicrm_api4'
            ? Mutation::isApi4WriteAction($action)
            : in_array(strtolower($action), Mutation::API3_WRITE_ACTIONS, true);

        return $writes ? sprintf("%s('%s', '%s')", $function, $entity, $action) : null;
    }

    private static function describeMethodCall(SourceFile $source, Node $call): ?string
    {
        $method = strtolower(SqlNodes::name($source, $call) ?? '');
        if ($method === '') {
            return null;
        }
        // ->save()/->delete() count only on something that names itself a DAO/BAO.
        if (in_array($method, ['save', 'delete'], true) && self::looksLikeRecord($source, SqlNodes::receiver($source, $call))) {
            return sprintf('->%s() on a DAO/BAO', $method);
        }
        if (!SqlNodes::isDatabaseCall($source, $call)) {
            return null;
        }
        $sql = self::sqlArgument($source, $call);

        return $sql === null ? null : sprintf('%s in ->%s()', $sql, $method);
    }

    private static function sqlArgument(SourceFile $source, Node $call): ?string
    {
        foreach (SqlNodes::argumentValues($source, $call) as $value) {
            $sql = SqlNodes::literalString($source, $value);
            $keyword = $sql === null ? null : Mutation::dmlKeyword($sql);
            if ($keyword !== null) {
                return $keyword;
            }
        }

        return null;
    }

    private static function looksLikeRecord(SourceFile $source, Node $node): bool
    {
        if ($node->kind === NodeKind::Instantiation || $node->kind === NodeKind::StaticMethodCall) {
            return Sql::isDaoClass(SqlNodes::className($source, $node));
        }
        $name = SqlNodes::variableName($source, $node);

        return $name !== null && preg_match('/(dao|bao)$/i', $name) === 1;
    }
}
