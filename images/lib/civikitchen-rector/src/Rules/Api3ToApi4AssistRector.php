<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * ASSISTED, deliberately partial api3 -> api4 migration to the ARRAY form
 * (minimal-change: keeps a function call, just restructures the params). A
 * literal `civicrm_api3('Entity', 'get', [...])` becomes the api4 array form,
 * PRESERVING api3 behavior with two guardrails:
 *   - add `checkPermissions => false` if absent  (api3 PHP default is FALSE,
 *     api4 default is TRUE)
 *   - add `limit => 25` if absent               (api3 `get` silently capped at 25)
 *
 * Sibling of Api3ToApi4OopAssistRector (which emits the idiomatic OOP builder).
 * Pick one via `ckmodernize --api=array` / `--api` (oop). Both BAIL on anything
 * unsafe (non-get actions, operator/array filter values, `api.*` chaining,
 * options beyond limit/offset, non-literal entity/action/params). Preview only.
 */
final class Api3ToApi4AssistRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [FuncCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof FuncCall || !$this->isName($node, 'civicrm_api3')) {
      return NULL;
    }
    $args = $node->getArgs();
    if (count($args) < 2) {
      return NULL;
    }
    $entity = $args[0]->value;
    $action = $args[1]->value;
    if (!$entity instanceof String_ || !$action instanceof String_ || strtolower($action->value) !== 'get') {
      return NULL;
    }
    $params = $args[2]->value ?? new Array_([]);
    if (!$params instanceof Array_) {
      return NULL;
    }

    $where = [];
    $top = [];
    $hasCheckPermissions = FALSE;
    $hasLimit = FALSE;

    foreach ($params->items as $item) {
      if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
        return NULL;
      }
      $key = $item->key->value;
      $value = $item->value;

      if ($key === 'sequential') {
        continue;
      }
      if ($key === 'return') {
        $top[] = new ArrayItem($value, new String_('select'));
        continue;
      }
      if ($key === 'check_permissions') {
        $top[] = new ArrayItem($value, new String_('checkPermissions'));
        $hasCheckPermissions = TRUE;
        continue;
      }
      if ($key === 'options') {
        if (!$value instanceof Array_) {
          return NULL;
        }
        foreach ($value->items as $opt) {
          if (!$opt instanceof ArrayItem || !$opt->key instanceof String_) {
            return NULL;
          }
          if ($opt->key->value === 'limit') {
            $top[] = new ArrayItem($opt->value, new String_('limit'));
            $hasLimit = TRUE;
          }
          elseif ($opt->key->value === 'offset') {
            $top[] = new ArrayItem($opt->value, new String_('offset'));
          }
          else {
            return NULL;
          }
        }
        continue;
      }
      if (str_starts_with($key, 'api.') || $value instanceof Array_) {
        return NULL;
      }
      $where[] = new ArrayItem(new Array_([
        new ArrayItem(new String_($key)),
        new ArrayItem(new String_('=')),
        new ArrayItem($value),
      ]));
    }

    $newItems = [];
    if ($where !== []) {
      $newItems[] = new ArrayItem(new Array_($where), new String_('where'));
    }
    foreach ($top as $topItem) {
      $newItems[] = $topItem;
    }
    if (!$hasLimit) {
      $newItems[] = new ArrayItem(new Int_(25), new String_('limit'));
    }
    if (!$hasCheckPermissions) {
      $newItems[] = new ArrayItem(new ConstFetch(new Name('false')), new String_('checkPermissions'));
    }

    $node->name = new Name('civicrm_api4');
    $newArg = new Arg(new Array_($newItems));
    if (isset($node->args[2])) {
      $node->args[2] = $newArg;
    }
    else {
      $node->args[] = $newArg;
    }

    return $node;
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Assisted, partial api3->api4 migration of literal get() calls to the array form (preview only; preserves checkPermissions + limit defaults)',
      [
        new CodeSample(
          "civicrm_api3('Contact', 'get', ['first_name' => 'Bob', 'return' => ['id']]);",
          "civicrm_api4('Contact', 'get', ['where' => [['first_name', '=', 'Bob']], 'select' => ['id'], 'limit' => 25, 'checkPermissions' => false]);"
        ),
      ]
    );
  }

}
