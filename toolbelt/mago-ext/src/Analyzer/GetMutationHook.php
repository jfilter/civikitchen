<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Options;
use CiviKitchen\Mago\Workspace;
use CiviKitchen\PHPStan\MutationOnGet;
use CiviKitchen\PHPStan\RouteCatalog;
use InvalidArgumentException;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A database write reachable from a route a browser can GET: a catalogued
 * handler, or a CRM_Core_Page's run(), anonymous pages included. See GetMutationRule.
 */
final class GetMutationHook implements NodeAnalysisHook
{
    private const SUPPRESSION = '// @mago-expect analysis:civikitchen/route-mutation-on-get';

    private ?RouteCatalog $routes = null;

    public function __construct(private readonly Options $options)
    {
    }

    public function getTargets(): array
    {
        return [NodeKind::Class_, NodeKind::Trait, NodeKind::AnonymousClass];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $methods = SqlNodes::methods($source, $context->node);
        if ($context->node->kind === NodeKind::AnonymousClass) {
            $this->check($context, 'class@anonymous', self::anonymousParents($source, $context->codebase, $context->node), $methods);

            return;
        }
        $name = SqlNodes::declaredName($source, $context->node);
        if ($name === null) {
            return;
        }
        $classes = $context->node->kind === NodeKind::Class_
            ? [[$context->codebase->getClass(strtolower($name)), $methods]]
            : self::traitUsers($context->codebase, $name, $methods);
        foreach ($classes as [$class, $own]) {
            if ($class !== null) {
                $name = str_starts_with($class->originalName, '{anonymous-class:') ? 'class@anonymous' : $class->originalName;
                $this->check($context, $name, $class->parentClasses, $own);
            }
        }
    }

    /**
     * The method body's nodes of the given kinds, minus those of a class declared
     * in it: its methods run only when called, an anonymous class's constructor
     * runs right there when $constructors.
     *
     * @param list<NodeKind> $kinds
     * @return list<Node>
     */
    private static function ownNodes(SourceFile $source, Node $method, array $kinds, bool $constructors = false): array
    {
        return array_values(array_filter(
            SqlNodes::bodyNodes($source, $method, $kinds),
            static fn (Node $node): bool => self::runsWith($source, $node, $method, $constructors),
        ));
    }

    private static function runsWith(SourceFile $source, Node $node, Node $method, bool $constructors): bool
    {
        $inner = null;
        foreach ($source->getAncestors($node) as $ancestor) {
            if ($ancestor->id === $method->id) {
                return true;
            }
            if ($ancestor->kind === NodeKind::Method) {
                $inner = $ancestor;
            } elseif ($ancestor->kind === NodeKind::AnonymousClass) {
                if (!$constructors || $inner === null || strtolower((string) SqlNodes::methodName($source, $inner)) !== '__construct') {
                    return false;
                }
                $inner = null;
            }
        }

        return true;
    }

    /**
     * The lowercase ancestors of an anonymous class, from its extends clause.
     *
     * @return list<string>
     */
    private static function anonymousParents(SourceFile $source, Codebase $codebase, Node $class): array
    {
        foreach ($source->getChildren($class) as $child) {
            if ($child->kind === NodeKind::Extends) {
                $parent = array_values(array_filter($source->getChildren($child), static fn (Node $node): bool => $node->kind !== NodeKind::Keyword))[0];
                $name = strtolower($source->getResolvedName($parent)?->name ?? ltrim($source->getText($parent), '\\'));

                return [$name, ...array_map('strtolower', $codebase->getClassAncestors($name))];
            }
        }

        return [];
    }

    /**
     * Classes using the trait, each with the trait methods it does not override:
     * a trait's methods are the class's own.
     *
     * @param array<string, Node> $methods
     * @return list<array{ClassLikeMetadata, array<string, Node>}>
     */
    private static function traitUsers(Codebase $codebase, string $trait, array $methods): array
    {
        $users = [];
        foreach ($codebase->getMultipleClasses($codebase->getClassNames()) as $class) {
            if ($class !== null && in_array(strtolower($trait), $class->usedTraits, true)) {
                $users[] = [$class, array_diff_key($methods, array_flip(array_map('strtolower', $class->methods)))];
            }
        }

        return $users;
    }

    /**
     * @param list<string> $parents lowercase
     * @param array<string, Node> $methods
     */
    private function check(NodeAnalysisContext $context, string $class, array $parents, array $methods): void
    {
        $source = $context->source;
        foreach ($this->handlers($source, $methods, $class, $parents) as $name => $route) {
            $reachable = self::reachable($source, $methods, $name);
            if (self::checksRequestMethod($source, $reachable)) {
                continue;
            }
            $handler = $class . '::' . SqlNodes::methodName($source, $methods[$name]) . '()';
            foreach ($reachable as $method) {
                foreach (self::ownNodes($source, $method, SqlNodes::CALLS, true) as $call) {
                    $what = MutationNodes::describe($source, $call);
                    if ($what !== null) {
                        $context->report(Level::Error, 'route-mutation-on-get', Issue::new(MutationOnGet::message($what, $handler, $route, self::SUPPRESSION), $call->span));
                    }
                }
            }
        }
    }

    /**
     * The methods a browser enters through, lowercase name => route path.
     *
     * @param array<string, Node> $methods
     * @param list<string> $parents lowercase
     * @return array<string, string>
     */
    private function handlers(SourceFile $source, array $methods, string $class, array $parents): array
    {
        $this->routes ??= new RouteCatalog(self::catalogPath($this->options->string('route-catalog')), Workspace::root());
        $handlers = [];
        foreach ($methods as $name => $method) {
            $route = SqlNodes::isStatic($source, $method)
                ? $this->routes->routeForMethod($class, (string) SqlNodes::methodName($source, $method))
                : null;
            if ($route !== null) {
                $handlers[$name] = $route;
            }
        }
        if (isset($methods['run']) && in_array(strtolower(MutationOnGet::PAGE_BASE), $parents, true)) {
            $handlers['run'] = $this->routes->routeForPage($class) ?? '';
        }

        return $handlers;
    }

    /** `--route-catalog`, relative to the workspace and required to exist; `.ck-routes.json` there by default. */
    private static function catalogPath(?string $option): string
    {
        $path = $option ?? '.ck-routes.json';
        $path = str_starts_with($path, '/') ? $path : Workspace::root() . '/' . $path;
        if ($option !== null && !is_file($path)) {
            throw new InvalidArgumentException("civikitchen mago extension: --route-catalog {$path} does not exist");
        }

        return $path;
    }

    /**
     * The handler plus the own methods it calls, MAX_HOPS deep.
     *
     * @param array<string, Node> $methods
     * @return list<Node>
     */
    private static function reachable(SourceFile $source, array $methods, string $root): array
    {
        $seen = [];
        $level = [$root];
        for ($hop = 0; $hop <= MutationOnGet::MAX_HOPS && $level !== []; $hop++) {
            $next = [];
            foreach ($level as $name) {
                if (isset($seen[$name]) || !isset($methods[$name])) {
                    continue;
                }
                $seen[$name] = $methods[$name];
                foreach (self::ownNodes($source, $methods[$name], [NodeKind::MethodCall, NodeKind::StaticMethodCall]) as $call) {
                    $own = SqlNodes::ownCall($source, $call);
                    if ($own !== null) {
                        $next[] = $own;
                    }
                }
            }
            $level = $next;
        }

        return array_values($seen);
    }

    /**
     * `->getMethod()`, `$_SERVER['REQUEST_METHOD']` or core's AJAX guard
     * anywhere on the path; the compared verb is not inspected.
     *
     * @param list<Node> $methods
     */
    private static function checksRequestMethod(SourceFile $source, array $methods): bool
    {
        $kinds = [NodeKind::MethodCall, NodeKind::StaticMethodCall, NodeKind::ArrayAccess];
        foreach ($methods as $method) {
            foreach (self::ownNodes($source, $method, $kinds) as $node) {
                if ($node->kind === NodeKind::ArrayAccess) {
                    [$base, $dim] = $source->getChildren($node);
                    if (SqlNodes::variableName($source, $base) === '_SERVER' && SqlNodes::literalString($source, $dim) === 'REQUEST_METHOD') {
                        return true;
                    }
                    continue;
                }
                $name = strtolower(SqlNodes::name($source, $node) ?? '');
                if ($node->kind === NodeKind::MethodCall ? $name === 'getmethod' : ($name === 'validateajaxrequestmethod'
                    && in_array(strtolower(ltrim(SqlNodes::className($source, $node) ?? '', '\\')), ['crm_core_page_ajax', 'self', 'static', 'parent'], true))) {
                    return true;
                }
            }
        }

        return false;
    }
}
