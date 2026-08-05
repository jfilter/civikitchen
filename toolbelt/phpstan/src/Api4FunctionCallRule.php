<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * `civicrm_api4('Entity', 'action', [...])` against the core contract.
 *
 * @implements Rule<FuncCall>
 */
final class Api4FunctionCallRule implements Rule
{
    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Name || $node->name->toLowerString() !== 'civicrm_api4') {
            return [];
        }
        $args = $node->getArgs();
        if (!isset($args[0])) {
            return [];
        }

        $entity = $this->contract->literalString($args[0]->value, $scope);
        if ($entity === null) {
            return [];
        }

        $errors = $this->contract->checkEntity($entity, 'civicrm_api4()');
        if ($errors !== []) {
            return $errors;
        }

        if (isset($args[1])) {
            $action = $this->contract->literalString($args[1]->value, $scope);
            if ($action !== null) {
                $errors = array_merge($errors, $this->contract->checkAction($entity, $action, 'civicrm_api4()'));
            }
        }

        if (isset($args[2])) {
            foreach ($this->contract->fieldsFromParams($scope->getType($args[2]->value), $scope) as [$field, $clause]) {
                $errors = array_merge($errors, $this->contract->checkField($entity, $field, $clause));
            }
        }

        return $errors;
    }
}
