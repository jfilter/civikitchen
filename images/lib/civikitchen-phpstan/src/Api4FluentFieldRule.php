<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Field names passed to the fluent APIv4 builder.
 *
 * Each `->addWhere(...)` is its own node, so the rule takes them one at a
 * time and walks back down the chain to find the entity the builder started
 * from. Only calls rooted in a `\Civi\Api4\X::action()` are considered — an
 * addWhere() on anything else is somebody else's builder.
 *
 * @implements Rule<MethodCall>
 */
final class Api4FluentFieldRule implements Rule
{
    /** Builder methods whose leading argument is a field name. */
    private const FIRST_ARG_IS_FIELD = ['addwhere', 'addvalue', 'addorderby', 'addgroupby'];

    /** Builder methods whose arguments are all field names. */
    private const ALL_ARGS_ARE_FIELDS = ['addselect'];

    /** Builder methods taking a whole clause array. */
    private const ARRAY_ARG = ['setselect', 'setwhere', 'setvalues', 'setorderby', 'setgroupby'];

    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }
        $method = $node->name->toLowerString();
        if (!in_array($method, [...self::FIRST_ARG_IS_FIELD, ...self::ALL_ARGS_ARE_FIELDS, ...self::ARRAY_ARG], true)) {
            return [];
        }

        $entity = Api4Fluent::entityOfChain($node, $scope);
        if ($entity === null || !Api4Catalog::hasCompleteFields($entity)) {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }

        $fields = [];
        if (in_array($method, self::FIRST_ARG_IS_FIELD, true)) {
            $field = $this->contract->literalString($args[0]->value, $scope);
            $fields = $field === null ? [] : [$field];
        } elseif (in_array($method, self::ALL_ARGS_ARE_FIELDS, true)) {
            foreach ($args as $arg) {
                // addSelect(...$columns) — a spread is not a name we know.
                if ($arg->unpack) {
                    continue;
                }
                $field = $this->contract->literalString($arg->value, $scope);
                if ($field !== null) {
                    $fields[] = $field;
                }
            }
        } else {
            $type = $scope->getType($args[0]->value);
            $fields = $method === 'setwhere'
                ? $this->contract->fieldsFromWhere($type)
                : ($method === 'setselect' ? $this->contract->listOfStrings($type) : $this->contract->keys($type));
        }

        $aliases = Api4Fluent::aliasesOfChain($node, $scope);

        $errors = [];
        foreach (array_diff($fields, $aliases) as $field) {
            $errors = array_merge($errors, $this->contract->checkField($entity, $field, $node->name->toString() . '()'));
        }

        return $errors;
    }
}
