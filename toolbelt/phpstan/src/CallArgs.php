<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;

/**
 * A call's argument for one parameter, passed by position or by name.
 *
 * The rules name the parameters of the core signatures they read, because
 * they often see a call without a reflected callee: a `->query()` receiver
 * matched by name, or a call graph walked without a scope. An argument list
 * that cannot be mapped — a spread, a first-class callable — yields null, and
 * the rules stay silent.
 */
final class CallArgs
{
    /** The expression bound to parameter $name at $position, or null. */
    public static function value(Node\Expr\CallLike $call, int $position, string $name): ?Node\Expr
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if ($arg->name === null ? $index === $position : $arg->name->toString() === $name) {
                return $arg->value;
            }
        }

        return null;
    }
}
