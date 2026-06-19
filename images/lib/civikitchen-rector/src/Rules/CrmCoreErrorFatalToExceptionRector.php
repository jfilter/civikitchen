<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrite the removed CRM_Core_Error::fatal($msg) into
 * throw new \CRM_Core_Exception($msg). Another cklint-banned footgun, fixed.
 */
final class CrmCoreErrorFatalToExceptionRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [StaticCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall) {
      return NULL;
    }
    if (!$this->isName($node->class, 'CRM_Core_Error') || !$this->isName($node->name, 'fatal')) {
      return NULL;
    }

    return new Throw_(new New_(new FullyQualified('CRM_Core_Exception'), $node->getArgs()));
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Replace removed CRM_Core_Error::fatal() with throw new CRM_Core_Exception()',
      [new CodeSample("CRM_Core_Error::fatal('Boom');", "throw new \\CRM_Core_Exception('Boom');")]
    );
  }

}
