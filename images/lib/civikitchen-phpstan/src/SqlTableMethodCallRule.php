<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Table names in `$dao->query()` and in the CRM_Utils_SQL_Select builder.
 *
 * The builder is matched by method name, not by receiver type: the fluent
 * chain often starts from a helper whose return type is not annotated. That
 * is safe because SqlSchema only ever speaks about `civicrm_`-prefixed
 * names it can prove wrong — `$collection->join('items')` says nothing.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class SqlTableMethodCallRule implements Rule
{
    private SqlSchema $schema;

    public function __construct(SqlSchema $schema)
    {
        $this->schema = $schema;
    }

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toLowerString() : '';
        $args = $node->getArgs();

        if (Sql::isDatabaseCall($node) && isset($args[0])) {
            $literal = Sql::literalString($args[0]->value);

            return $literal === null ? [] : $this->schema->checkSql($literal, sprintf('->%s()', $method));
        }

        // ->from('civicrm_contact c'); ->join('e', 'INNER JOIN civicrm_email e ON ...')
        if ($method === 'from' && isset($args[0])) {
            $literal = Sql::literalString($args[0]->value);

            return $literal === null ? [] : $this->schema->checkTableClause($literal, '->from()');
        }
        if ($method === 'join' && isset($args[1])) {
            $literal = Sql::literalString($args[1]->value);

            return $literal === null ? [] : $this->schema->checkSql($literal, '->join()');
        }

        return [];
    }
}
