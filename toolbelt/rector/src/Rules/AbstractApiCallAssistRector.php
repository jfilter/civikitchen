<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;

/**
 * Shared skeleton of the civicrm_api3/civicrm_api4 call rewriters: match a
 * literal `<function>('Entity', 'action', [...])` and hand the parts to the
 * concrete rule, which bails (NULL) on anything outside its safe subset.
 */
abstract class AbstractApiCallAssistRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [FuncCall::class];
  }

  /**
   * Entity, action and params array of a literal API call, or NULL to bail
   * (wrong function, dynamic entity/action, non-literal params). A missing
   * params argument counts as an empty array, matching both API runtimes.
   *
   * @return array{String_, String_, Array_}|null
   */
  protected function matchLiteralApiCall(Node $node, string $function): ?array {
    if (!$node instanceof FuncCall || !$this->isName($node, $function)) {
      return NULL;
    }
    $args = $node->getArgs();
    if (count($args) < 2) {
      return NULL;
    }
    $entity = $args[0]->value;
    $action = $args[1]->value;
    if (!$entity instanceof String_ || !$action instanceof String_) {
      return NULL;
    }
    $params = $args[2]->value ?? new Array_([]);
    if (!$params instanceof Array_) {
      return NULL;
    }

    return [$entity, $action, $params];
  }

  /**
   * limit/offset of an api3 `options` array; NULL bails on any other option.
   *
   * @return array{limit: ?Expr, offset: ?Expr}|null
   */
  protected function limitOffsetOptions(Expr $value): ?array {
    if (!$value instanceof Array_) {
      return NULL;
    }
    $options = ['limit' => NULL, 'offset' => NULL];
    foreach ($value->items as $opt) {
      if (!$opt instanceof ArrayItem || !$opt->key instanceof String_ || !array_key_exists($opt->key->value, $options)) {
        return NULL;
      }
      $options[$opt->key->value] = $opt->value;
    }

    return $options;
  }

}
