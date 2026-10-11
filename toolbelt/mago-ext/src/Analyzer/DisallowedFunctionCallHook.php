<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Analyzer\Disallowed\Bans;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;

/** Calls of the global functions civicrm-disallowed.php bans. */
final class DisallowedFunctionCallHook implements NodeAnalysisHook
{
    /** @var array<string, array{name: string, message: string, allowIn: list<string>}> */
    private readonly array $bans;

    public function __construct()
    {
        $this->bans = Bans::functions();
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $callee = $source->getChildren($context->node)[0] ?? null;
        while ($callee?->kind === NodeKind::Expression) {
            $callee = $source->getChildren($callee)[0] ?? null;
        }
        if ($callee?->kind !== NodeKind::Identifier) {
            return;
        }
        $resolved = $source->getResolvedName($callee)?->name;
        if ($resolved === null) {
            return;
        }
        // PHP's fallback: an unqualified name in a namespace means the global function unless the namespaced one exists.
        $short = substr($resolved, (int) strrpos('\\' . $resolved, '\\'));
        $identifier = $source->getChildren($callee)[0] ?? null;
        if ($identifier?->kind === NodeKind::LocalIdentifier && !$context->codebase->functionExists($resolved)) {
            $resolved = $short;
        }
        $ban = $this->bans[strtolower(ltrim($resolved, '\\'))] ?? null;
        if ($ban === null || Bans::allowed($ban, $source->path)) {
            return;
        }
        $context->report(Level::Error, 'disallowed-function', Issue::new(
            sprintf('Calling %s() is forbidden, %s.', $ban['name'], $ban['message']),
            $context->node->span,
        ));
    }
}
