<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Convert the api4 ARRAY form to the idiomatic OO builder — fully SAFE: same API
 * version, same semantics, same Result. Pure style.
 *
 *   civicrm_api4('Contact', 'get', ['where' => [['x', '=', 1]], 'select' => ['id'], 'limit' => 5])
 *   -> \Civi\Api4\Contact::get()->addWhere('x', '=', 1)->addSelect('id')->setLimit(5)->execute()
 *
 * checkPermissions becomes the action() argument (api4 defaults TRUE in both
 * forms, so an absent value maps to no argument). Bails on anything it doesn't
 * model yet (join/having/chain/groupBy-with-keys, non-literal entity/action/params).
 */
final class Api4ArrayToOopRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [FuncCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof FuncCall || !$this->isName($node, 'civicrm_api4')) {
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

    $permArg = NULL;
    $methods = [];

    foreach ($params->items as $item) {
      if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
        return NULL;
      }
      $key = $item->key->value;
      $value = $item->value;

      switch ($key) {
        case 'checkPermissions':
          $permArg = $value;
          break;

        case 'where':
          if (!$value instanceof Array_) {
            return NULL;
          }
          foreach ($value->items as $row) {
            if (!$row instanceof ArrayItem || $row->key !== NULL || !$row->value instanceof Array_) {
              return NULL;
            }
            $cellArgs = [];
            foreach ($row->value->items as $cell) {
              if (!$cell instanceof ArrayItem || $cell->key !== NULL) {
                return NULL;
              }
              $cellArgs[] = new Arg($cell->value);
            }
            if (count($cellArgs) < 2) {
              return NULL;
            }
            $methods[] = ['addWhere', $cellArgs];
          }
          break;

        case 'select':
          if ($value instanceof Array_) {
            $selectArgs = [];
            foreach ($value->items as $sel) {
              if (!$sel instanceof ArrayItem || $sel->key !== NULL) {
                return NULL;
              }
              $selectArgs[] = new Arg($sel->value);
            }
            $methods[] = ['addSelect', $selectArgs];
          }
          else {
            $methods[] = ['setSelect', [new Arg($value)]];
          }
          break;

        case 'orderBy':
          if (!$value instanceof Array_) {
            return NULL;
          }
          foreach ($value->items as $ord) {
            if (!$ord instanceof ArrayItem || !$ord->key instanceof String_) {
              return NULL;
            }
            $methods[] = ['addOrderBy', [new Arg(new String_($ord->key->value)), new Arg($ord->value)]];
          }
          break;

        case 'limit':
          $methods[] = ['setLimit', [new Arg($value)]];
          break;

        case 'offset':
          $methods[] = ['setOffset', [new Arg($value)]];
          break;

        case 'values':
          $methods[] = ['setValues', [new Arg($value)]];
          break;

        default:
          // join, having, chain, groupBy-with-aliases, ... — not modeled yet.
          return NULL;
      }
    }

    $expr = new StaticCall(
      new FullyQualified('Civi\\Api4\\' . $entity->value),
      $action->value,
      $permArg !== NULL ? [new Arg($permArg)] : []
    );
    foreach ($methods as [$name, $methodArgs]) {
      $expr = new MethodCall($expr, $name, $methodArgs);
    }

    return new MethodCall($expr, 'execute');
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Convert api4 array-form calls to the idiomatic OO builder form (same semantics)',
      [
        new CodeSample(
          "civicrm_api4('Contact', 'get', ['where' => [['x', '=', 1]], 'limit' => 5]);",
          "\\Civi\\Api4\\Contact::get()->addWhere('x', '=', 1)->setLimit(5)->execute();"
        ),
      ]
    );
  }

}
