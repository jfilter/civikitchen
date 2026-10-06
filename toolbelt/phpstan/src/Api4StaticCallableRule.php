<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\Rule;

/**
 * `\Civi\Api4\Contact::get(...)` — phpstan hands a first-class callable to
 * rules as its own node, so the fluent-call check needs a second binding.
 *
 * @implements Rule<StaticMethodCallableNode>
 */
final class Api4StaticCallableRule implements Rule
{
    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return Api4StaticCallRule::check($this->contract, $node->getOriginalNode(), $scope);
    }
}
