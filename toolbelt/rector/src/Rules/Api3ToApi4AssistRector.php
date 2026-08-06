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
final class Api3ToApi4AssistRector extends AbstractApiCallAssistRector {

  public function refactor(Node $node): ?Node {
    if (!$node instanceof FuncCall) {
      return NULL;
    }
    $match = $this->matchLiteralApiCall($node, 'civicrm_api3');
    if ($match === NULL) {
      return NULL;
    }
    [, $action, $params] = $match;
    if (strtolower($action->value) !== 'get') {
      return NULL;
    }

    $parts = $this->classifyApi3GetParams($params);
    if ($parts === NULL) {
      return NULL;
    }

    $newItems = [];
    if ($parts['where'] !== []) {
      $whereItems = [];
      foreach ($parts['where'] as [$field, $value]) {
        $whereItems[] = new ArrayItem(new Array_([
          new ArrayItem(new String_($field)),
          new ArrayItem(new String_('=')),
          new ArrayItem($value),
        ]));
      }
      $newItems[] = new ArrayItem(new Array_($whereItems), new String_('where'));
    }
    $hasCheckPermissions = FALSE;
    $hasLimit = FALSE;
    foreach ($parts['top'] as [$clause, $value]) {
      $newItems[] = new ArrayItem($value, new String_($clause));
      $hasLimit = $hasLimit || $clause === 'limit';
      $hasCheckPermissions = $hasCheckPermissions || $clause === 'checkPermissions';
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
