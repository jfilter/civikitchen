<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * `\Civi\Api4\Entity::action()` — the entity and action half of the fluent form.
 *
 * The action is a magic static, so php itself never sees the typo; only
 * __callStatic does, at runtime, on the site that calls it.
 *
 * @implements Rule<StaticCall>
 */
final class Api4StaticCallRule implements Rule
{
    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $entity = Api4Fluent::entityFromStaticCall($node, $scope);
        if ($entity === null || !$node->name instanceof Node\Identifier) {
            return [];
        }

        $errors = $this->contract->checkEntity($entity, '\\Civi\\Api4\\' . $entity);
        if ($errors !== []) {
            return $errors;
        }

        return $this->contract->checkAction($entity, $node->name->toString(), 'fluent APIv4 call');
    }
}
