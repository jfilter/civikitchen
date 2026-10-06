<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Table names in `CRM_Core_DAO::executeQuery()` and friends.
 *
 * @implements Rule<Node\Expr\StaticCall>
 *
 * @see SqlSchema for what is judged and what is passed over in silence
 */
final class SqlTableStaticCallRule implements Rule
{
    private SqlSchema $schema;

    public function __construct(SqlSchema $schema)
    {
        $this->schema = $schema;
    }

    public function getNodeType(): string
    {
        return Node\Expr\StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = ltrim(Sql::staticClassName($node) ?? '', '\\');
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        // executeQuery($query, ...), singleValueQuery($query, ...), executeUnbufferedQuery($query, ...)
        if (Sql::isDatabaseCall($node)) {
            $literal = self::literal(CallArgs::value($node, 0, 'query'));

            return $literal === null ? [] : $this->schema->checkSql($literal, sprintf('%s::%s()', $class, $method));
        }

        // CRM_Utils_SQL_Select::from($from, $options = [])
        if (strcasecmp($class, 'CRM_Utils_SQL_Select') === 0 && strtolower($method) === 'from') {
            $literal = self::literal(CallArgs::value($node, 0, 'from'));

            return $literal === null ? [] : $this->schema->checkTableClause($literal, 'CRM_Utils_SQL_Select::from()');
        }

        return [];
    }

    private static function literal(?Node\Expr $expr): ?string
    {
        return $expr === null ? null : Sql::literalString($expr);
    }
}
