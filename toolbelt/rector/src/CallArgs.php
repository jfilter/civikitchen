<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;

/**
 * A call's arguments keyed by the callee's parameter names, positional and
 * named alike — or NULL when a spread, an unknown or repeated name, or an
 * argument beyond the listed parameters makes the mapping unsafe to rewrite.
 */
final class CallArgs {

  /**
   * @param list<string> $params
   *   Parameter names in declaration order.
   *
   * @return array<string, Expr>|null
   */
  public static function byName(CallLike $call, array $params): ?array {
    if ($call->isFirstClassCallable()) {
      return NULL;
    }
    $bound = [];
    foreach ($call->getArgs() as $position => $arg) {
      $name = $arg->name === NULL ? ($params[$position] ?? NULL) : $arg->name->toString();
      if ($arg->unpack || $name === NULL || !in_array($name, $params, TRUE) || isset($bound[$name])) {
        return NULL;
      }
      $bound[$name] = $arg->value;
    }

    return $bound;
  }

}
