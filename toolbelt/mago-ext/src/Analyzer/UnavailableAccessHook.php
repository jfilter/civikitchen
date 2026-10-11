<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Guards;
use CiviKitchen\Mago\Linter\Support\Syntax;
use CiviKitchen\Mago\Options;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\VersionRange;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * With --compatibility (ckcompat): constants, enum cases, static calls and
 * static properties of what the floor lacks, which mago checks only on `new`
 * and calls. instanceof, type hints and ::class stay silent, as PHP does.
 */
final class UnavailableAccessHook implements NodeAnalysisHook
{
    private readonly bool $active;

    public function __construct(Options $options)
    {
        $this->active = $options->string('compatibility') !== null;
    }

    public function getTargets(): array
    {
        return [NodeKind::ClassConstantAccess, NodeKind::StaticMethodCall, NodeKind::StaticPropertyAccess, NodeKind::ConstantAccess];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (!$this->active) {
            return;
        }
        $source = $context->source;
        $node = $context->node;
        $floor = $context->phpVersion;
        $version = Guards::version($source->contents);
        if ($version !== null && version_compare($version, $floor->major() . '.' . $floor->minor(), '>')) {
            return;
        }
        $children = $source->getChildren($node);
        $first = isset($children[0]) ? Syntax::unwrap($source, $children[0]) : null;
        if ($first === null) {
            return;
        }
        if ($node->kind === NodeKind::ConstantAccess) {
            $this->constant($context, $first);
            return;
        }
        // self, static, parent and expressions name the code's own or a runtime class.
        if (!in_array($first->kind, [NodeKind::Identifier, NodeKind::FullyQualifiedIdentifier, NodeKind::QualifiedIdentifier, NodeKind::LocalIdentifier], true)) {
            return;
        }
        $class = ltrim($source->getResolvedName($first)?->name ?? $source->getText($first), '\\');
        $metadata = $context->codebase->getClassLike($class);
        if ($metadata === null || Guards::classLike($source->contents, $class)) {
            return;
        }
        if (self::missing($metadata->availableVersions, $floor)) {
            // mago reports a newer method itself; the class would repeat it.
            if ($node->kind === NodeKind::StaticMethodCall && $this->methodReported($context, $metadata->originalName, $children[1] ?? null)) {
                return;
            }
            $this->report($context, "The class `{$metadata->originalName}` is not available in PHP {$this->shown($floor)}.", $first);
            return;
        }
        if ($node->kind !== NodeKind::ClassConstantAccess) {
            return;
        }
        $member = trim($source->getText($children[count($children) - 1]));
        if (preg_match('/^\w+$/', $member) !== 1 || strcasecmp($member, 'class') === 0) {
            return;
        }
        $case = $context->codebase->getEnumCase($metadata->name, $member);
        if ($case !== null) {
            if (self::missing($case->availableVersions, $floor)) {
                $this->report($context, "The enum case `{$metadata->originalName}::{$member}` is not available in PHP {$this->shown($floor)}.", $node);
            }
            return;
        }
        $constant = $context->codebase->getClassConstant($metadata->name, $member);
        if ($constant !== null && self::missing($constant->availableVersions, $floor)) {
            $this->report($context, "The class constant `{$metadata->originalName}::{$member}` is not available in PHP {$this->shown($floor)}.", $node);
        }
    }

    private function constant(NodeAnalysisContext $context, Node $identifier): void
    {
        $source = $context->source;
        $written = ltrim($source->getText($identifier), '\\');
        $resolved = ltrim($source->getResolvedName($identifier)?->name ?? $written, '\\');
        // An unqualified constant falls back to the global one, as PHP does.
        $metadata = $context->codebase->getConstant($resolved) ?? (str_contains($written, '\\') ? null : $context->codebase->getConstant($written));
        if ($metadata === null || Guards::constant($source->contents, $metadata->name) || !self::missing($metadata->availableVersions, $context->phpVersion)) {
            return;
        }
        $this->report($context, "The constant `{$metadata->name}` is not available in PHP {$this->shown($context->phpVersion)}.", $identifier);
    }

    private function methodReported(NodeAnalysisContext $context, string $class, ?Node $selector): bool
    {
        $name = $selector === null ? '' : trim($context->source->getText($selector));
        $method = preg_match('/^\w+$/', $name) === 1 ? $context->codebase->getMethod($class, $name) : null;

        return $method !== null && self::missing($method->availableVersions, $context->phpVersion);
    }

    /** @param list<VersionRange> $ranges */
    private static function missing(array $ranges, PHPVersion $floor): bool
    {
        foreach ($ranges as $range) {
            if ($range->minimum === null || $floor->isAtLeast($range->minimum)) {
                return false;
            }
        }

        return $ranges !== [];
    }

    private function shown(PHPVersion $version): string
    {
        return $version->major() . '.' . $version->minor();
    }

    private function report(NodeAnalysisContext $context, string $message, Node $node): void
    {
        $context->report(Level::Error, 'unavailable-access', Issue::new($message, $node->span));
    }
}
