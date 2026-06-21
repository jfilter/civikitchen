<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Like Api3ToApi4AssistRector, but emits the idiomatic OOP builder form:
 *
 *   civicrm_api3('Contact', 'get', ['first_name' => 'Bob', 'return' => ['id']])
 *   -> \Civi\Api4\Contact::get(FALSE)->addWhere('first_name', '=', 'Bob')
 *        ->addSelect('id')->setLimit(25)->execute()
 *
 * Same safe subset + guardrails (checkPermissions becomes the get() argument,
 * defaulting to FALSE to preserve api3 behavior; limit defaults to 25). Same
 * bail-outs (non-get, operators, chaining, options beyond limit/offset,
 * non-literal params). Preview only.
 */
final class Api3ToApi4OopAssistRector extends AbstractRector {

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

    $whereRows = [];
    $select = NULL;
    $limit = NULL;
    $offset = NULL;
    $checkPermissions = NULL;

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
        $select = $value;
        continue;
      }
      if ($key === 'check_permissions') {
        $checkPermissions = $value;
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
            $limit = $opt->value;
          }
          elseif ($opt->key->value === 'offset') {
            $offset = $opt->value;
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
      $whereRows[] = [$key, $value];
    }

    // checkPermissions -> the get() argument. Absent => FALSE (api3 default).
    if ($checkPermissions === NULL) {
      $permArg = $this->bool(FALSE);
    }
    elseif ($checkPermissions instanceof Int_) {
      $permArg = $this->bool($checkPermissions->value !== 0);
    }
    else {
      $permArg = $checkPermissions;
    }

    $expr = new StaticCall(new FullyQualified('Civi\\Api4\\' . $entity->value), 'get', [new Arg($permArg)]);
    foreach ($whereRows as [$field, $value]) {
      $expr = new MethodCall($expr, 'addWhere', [new Arg(new String_($field)), new Arg(new String_('=')), new Arg($value)]);
    }
    if ($select !== NULL) {
      if ($select instanceof Array_) {
        $selectArgs = [];
        foreach ($select->items as $sel) {
          if (!$sel instanceof ArrayItem || $sel->key !== NULL) {
            return NULL;
          }
          $selectArgs[] = new Arg($sel->value);
        }
        $expr = new MethodCall($expr, 'addSelect', $selectArgs);
      }
      else {
        $expr = new MethodCall($expr, 'setSelect', [new Arg($select)]);
      }
    }
    // Guardrail: preserve the api3 get() default cap of 25.
    $expr = new MethodCall($expr, 'setLimit', [new Arg($limit ?? new Int_(25))]);
    if ($offset !== NULL) {
      $expr = new MethodCall($expr, 'setOffset', [new Arg($offset)]);
    }

    return new MethodCall($expr, 'execute');
  }

  private function bool(bool $value): ConstFetch {
    return new ConstFetch(new Name($value ? 'true' : 'false'));
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Assisted, partial api3->api4 migration of literal get() calls to the OOP builder form (preview only; preserves checkPermissions + limit defaults)',
      [
        new CodeSample(
          "civicrm_api3('Contact', 'get', ['first_name' => 'Bob', 'return' => ['id']]);",
          "\\Civi\\Api4\\Contact::get(FALSE)->addWhere('first_name', '=', 'Bob')->addSelect('id')->setLimit(25)->execute();"
        ),
      ]
    );
  }

}
