<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A route reachable by GET must not change data.
 *
 * "GET is safe" is not a style preference: browsers, prefetchers, link
 * scanners and corporate proxies fetch URLs on their own, a logged-in user
 * only has to look at a page for an `<img src="…/civicrm/x/delete?id=5">`
 * to fire, and every back button re-runs the write. A handler that creates
 * or deletes on GET is therefore a CSRF hole and a data-integrity problem
 * at the same time.
 *
 * Two kinds of handler are checked: the static methods a repo's xml/Menu
 * declares as page_callback (read from the generated route catalog, see
 * RouteCatalog) and the run() of a CRM_Core_Page subclass. Writes are
 * followed two hops through the class's own methods, because the endpoint
 * itself is usually three lines that delegate.
 *
 * Silent when the handler checks the request method — `$request->getMethod()
 * !== 'POST'` with a 405 answer is the whole point, and a handler that only
 * writes on POST is fine. The other legitimate case, a GET that writes on
 * purpose (a redirect flow with its own CSRF mitigation), is a narrow
 * `@phpstan-ignore ck.route.mutationOnGet` carrying the reason.
 *
 * @implements Rule<InClassNode>
 */
final class GetMutationRule implements Rule
{
    private const PAGE_BASE = 'CRM_Core_Page';

    private const MAX_HOPS = 2;

    private const ADVICE = 'a route a browser can GET must not change data — check the request method, or document the exception with @phpstan-ignore ck.route.mutationOnGet.';

    private RouteCatalog $routes;

    public function __construct(RouteCatalog $routes)
    {
        $this->routes = $routes;
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();

        /** @var array<string, Node\Stmt\ClassMethod> $methods */
        $methods = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->toLowerString()] = $stmt;
            }
        }

        $errors = [];
        foreach (self::handlers($methods, $class, $node->getClassReflection(), $this->routes) as $name => $route) {
            $reachable = self::reachable($methods, $name);
            if (self::checksRequestMethod($reachable)) {
                continue;
            }
            foreach ($reachable as $method) {
                foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr) as $expr) {
                    $what = Mutation::describe($expr);
                    if ($what === null) {
                        continue;
                    }
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        '%s writes from %s, which %s — %s',
                        $what,
                        $class . '::' . $methods[$name]->name->toString() . '()',
                        $route === '' ? 'is reachable by GET' : sprintf('answers GET %s', $route),
                        self::ADVICE,
                    ))
                        ->identifier('ck.route.mutationOnGet')
                        ->line($expr->getStartLine())
                        ->build();
                }
            }
        }

        return $errors;
    }

    /**
     * The methods of this class a browser enters through, lowercase name =>
     * route path.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return array<string, string>
     */
    private static function handlers(array $methods, string $class, ClassReflection $reflection, RouteCatalog $routes): array
    {
        $handlers = [];
        foreach ($methods as $name => $method) {
            if (!$method->isStatic()) {
                continue;
            }
            $route = $routes->routeForMethod($class, $method->name->toString());
            if ($route !== null) {
                $handlers[$name] = $route;
            }
        }

        if (isset($methods['run']) && self::isPage($reflection)) {
            $handlers['run'] = $routes->routeForPage($class) ?? '';
        }

        return $handlers;
    }

    /** A CRM_Core_Page subclass — its run() IS the route. */
    private static function isPage(ClassReflection $reflection): bool
    {
        foreach ($reflection->getParentClassesNames() as $parent) {
            if ($parent === self::PAGE_BASE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The handler plus the class's own methods it calls, MAX_HOPS deep.
     *
     * An endpoint is typically a few lines of HTTP handling around a helper
     * that does the work, so a rule that only reads the handler body sees
     * nothing at all. Two hops is where it stops: further down the call
     * graph a write is no longer obviously this route's doing.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return list<Node\Stmt\ClassMethod>
     */
    private static function reachable(array $methods, string $root): array
    {
        $seen = [];
        $level = [$root];
        for ($hop = 0; $hop <= self::MAX_HOPS && $level !== []; $hop++) {
            $next = [];
            foreach ($level as $name) {
                if (isset($seen[$name]) || !isset($methods[$name])) {
                    continue;
                }
                $seen[$name] = $methods[$name];
                foreach (self::localCalls($methods[$name]) as $callee) {
                    $next[] = $callee;
                }
            }
            $level = $next;
        }

        return array_values($seen);
    }

    /**
     * Calls to this class's own methods: `$this->x()`, `self::x()`,
     * `static::x()`.
     *
     * @return list<string>
     */
    private static function localCalls(Node\Stmt\ClassMethod $method): array
    {
        $names = [];
        foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall) as $call) {
            if (!$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\StaticCall) {
                continue;
            }
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call instanceof Node\Expr\MethodCall) {
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $names[] = $call->name->toLowerString();
                }
                continue;
            }
            $class = strtolower(ltrim(Sql::staticClassName($call) ?? '', '\\'));
            if ($class === 'self' || $class === 'static') {
                $names[] = $call->name->toLowerString();
            }
        }

        return $names;
    }

    /**
     * Does anything on this path look at the request method?
     *
     * Either the PSR-7 `$request->getMethod()` or the raw
     * `$_SERVER['REQUEST_METHOD']`. The comparison value is not inspected:
     * a handler that reads the method at all is deciding on it, and second-
     * guessing which verb it allows would only produce noise.
     *
     * @param list<Node\Stmt\ClassMethod> $methods
     */
    private static function checksRequestMethod(array $methods): bool
    {
        foreach ($methods as $method) {
            foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr) as $expr) {
                if ($expr instanceof Node\Expr\MethodCall
                    && $expr->name instanceof Node\Identifier
                    && $expr->name->toLowerString() === 'getmethod') {
                    return true;
                }
                if ($expr instanceof Node\Expr\ArrayDimFetch
                    && $expr->var instanceof Node\Expr\Variable
                    && $expr->var->name === '_SERVER'
                    && $expr->dim !== null
                    && Sql::literalString($expr->dim) === 'REQUEST_METHOD') {
                    return true;
                }
            }
        }

        return false;
    }
}
