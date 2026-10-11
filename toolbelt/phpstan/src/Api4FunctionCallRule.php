<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;

/**
 * `civicrm_api4('Entity', 'action', [...])` against the core contract.
 *
 * @implements Rule<FuncCall>
 */
final class Api4FunctionCallRule implements Rule
{
    private Api4Contract $contract;

    private ReflectionProvider $reflection;

    public function __construct(Api4Contract $contract, ReflectionProvider $reflection)
    {
        $this->contract = $contract;
        $this->reflection = $reflection;
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Resolved as PHP does: an alias counts, a namespace's own civicrm_api4() does not.
        if (!$node->name instanceof Node\Name || strtolower((string) $this->reflection->resolveFunctionName($node->name, $scope)) !== 'civicrm_api4') {
            return [];
        }
        // civicrm_api4(string $entity, string $action, array $params = [], $index = NULL)
        $entityArg = CallArgs::value($node, 0, 'entity');
        $entity = $entityArg === null ? null : $this->contract->literalString($entityArg, $scope);
        if ($entity === null) {
            return [];
        }

        $errors = $this->contract->checkEntity($entity, 'civicrm_api4()');
        if ($errors !== []) {
            return $errors;
        }

        $actionArg = CallArgs::value($node, 1, 'action');
        $action = $actionArg === null ? null : $this->contract->literalString($actionArg, $scope);
        if ($action !== null) {
            $errors = array_merge($errors, $this->contract->checkAction($entity, $action, 'civicrm_api4()'));
        }

        $params = CallArgs::value($node, 2, 'params');
        if ($action !== null && Api4Contract::readsEntityFields($action) && $params !== null) {
            foreach ($this->contract->fieldsFromParams($scope->getType($params), $scope) as [$field, $clause]) {
                $errors = array_merge($errors, $this->contract->checkField($entity, $field, $clause));
            }
        }

        return $errors;
    }
}
