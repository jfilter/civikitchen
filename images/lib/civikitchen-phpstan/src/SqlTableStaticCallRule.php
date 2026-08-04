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
        $args = $node->getArgs();
        if (!isset($args[0])) {
            return [];
        }
        $literal = Sql::literalString($args[0]->value);
        if ($literal === null) {
            return [];
        }

        if (Sql::isDatabaseCall($node)) {
            return $this->schema->checkSql($literal, sprintf('%s::%s()', $class, $method));
        }

        // CRM_Utils_SQL_Select::from('civicrm_contact c')
        if ($class === 'CRM_Utils_SQL_Select' && strtolower($method) === 'from') {
            return $this->schema->checkTableClause($literal, 'CRM_Utils_SQL_Select::from()');
        }

        return [];
    }
}
