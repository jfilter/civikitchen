<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrite the deprecated CRM_Utils_Array::value('k', $arr, $default) into
 * $arr['k'] ?? $default. This is one of the footguns cklint already BANS —
 * ckmodernize fixes it.
 */
final class CrmUtilsArrayValueToCoalesceRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [StaticCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall) {
      return NULL;
    }
    if (!$this->isName($node->class, 'CRM_Utils_Array') || !$this->isName($node->name, 'value')) {
      return NULL;
    }
    $args = $node->getArgs();
    if (count($args) < 2) {
      return NULL;
    }
    $default = $args[2]->value ?? new ConstFetch(new Name('null'));

    return new Coalesce(new ArrayDimFetch($args[1]->value, $args[0]->value), $default);
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Replace deprecated CRM_Utils_Array::value() with the null-coalescing operator',
      [new CodeSample("CRM_Utils_Array::value('k', \$a, 'd');", "\$a['k'] ?? 'd';")]
    );
  }

}
