<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Analyzer\Disallowed\Bans;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;

/** Static calls of the methods civicrm-disallowed.php bans, also through a subclass, alias or class-string. */
final class DisallowedStaticCallHook implements MethodCallAnalysisHook
{
    /** @var array<string, array{name: string, class: string, method: string, message: string, allowIn: list<string>}> */
    private readonly array $bans;

    public function __construct()
    {
        $this->bans = Bans::staticMethodsByMethod();
    }

    public function getTargets(): array
    {
        return array_map(static fn (array $ban): MethodTarget => MethodTarget::exact($ban['class'], $ban['method']), array_values($this->bans));
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::ReceiverType];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        if ($context->node->kind !== NodeKind::StaticMethodCall) {
            return;
        }
        $call = CallExpression::fromNode($source, $context->node);
        $method = $call->getName($source);
        if ($method === null) {
            return;
        }
        // mago fires this hook only for calls it resolved to a banned method; the receiver only names the "as".
        $receiver = match ($call->callee->kind) {
            NodeKind::Identifier => $source->getResolvedName($call->callee)?->name,
            NodeKind::Keyword => self::keywordClass($context, strtolower($source->getText($call->callee))),
            default => self::className($context->receiverType),
        };
        $receiver = $receiver === null ? null : ltrim($receiver, '\\');
        $ban = $this->bans[strtolower($method)] ?? null;
        if ($ban === null || Bans::allowed($ban, $source->path)) {
            return;
        }
        $as = $receiver === null || strcasecmp($ban['class'], $receiver) === 0 ? '' : sprintf(' (as %s::%s())', $receiver, $method);
        $context->report(Level::Error, 'disallowed-method', Issue::new(
            sprintf('Calling %s()%s is forbidden, %s.', $ban['name'], $as, $ban['message']),
            $context->node->span,
        ));
    }

    /** The class `self`/`static`/`parent` names: the innermost class-like declared around the call, or its parent. */
    private static function keywordClass(NodeAnalysisContext $context, string $keyword): ?string
    {
        $span = $context->node->span;
        $enclosing = null;
        foreach ($context->codebase->getMultipleClassLikes($context->codebase->getClassLikeNames()) as $class) {
            $location = $class?->location;
            if ($location === null || $location->file !== $context->source->path || $location->span->start > $span->start || $location->span->end < $span->end) {
                continue;
            }
            if ($enclosing === null || $location->span->start > $enclosing->location->span->start) {
                $enclosing = $class;
            }
        }

        return $keyword === 'parent' ? $enclosing?->directParentClass : $enclosing?->originalName;
    }

    private static function className(?Type $type): ?string
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }
        $atomic = $type->atomicTypes[0];

        return $atomic instanceof NamedObjectType ? ltrim($atomic->name, '\\') : $type->getLiteralClassString();
    }
}
