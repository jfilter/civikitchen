<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Support\Syntax;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Language deprecations mago has no rule for, reported from the PHP version that
 * deprecates them on. Off by default: ckcompat runs it at the top of the range.
 */
final class PhpDeprecationRule implements Rule
{
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/php-deprecation',
            name: 'PHP deprecations',
            description: 'Language features the file\'s PHP version deprecates, where mago has no rule of its own.',
            defaultLevel: Level::Error,
            defaultEnabled: false,
            targets: [NodeKind::FunctionCall, NodeKind::InterpolatedString, NodeKind::DocumentString, NodeKind::ShellExecuteString],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $node = $context->node;
        if ($node->kind !== NodeKind::FunctionCall) {
            if (!$source->phpVersion->isAtLeast(PHPVersion::fromParts(8, 2))) {
                return;
            }
            // `${…}` is an indirect variable standing as a string part of this very string.
            foreach ($source->getDescendants($node, NodeKind::IndirectVariable) as $indirect) {
                $ancestors = array_slice($source->getAncestors($indirect), 0, 4);
                $kinds = array_map(static fn ($ancestor): NodeKind => $ancestor->kind, array_slice($ancestors, 0, 3));
                if ($kinds === [NodeKind::Variable, NodeKind::Expression, NodeKind::StringPart] && ($ancestors[3] ?? null)?->id === $node->id) {
                    $context->report(Issue::new('`${…}` string interpolation is deprecated since PHP 8.2; write `{$…}`', $indirect->span));
                }
            }
            return;
        }
        $callee = Syntax::unwrap($source, $source->getChildren($node)[0]);
        $name = Syntax::calledGlobalFunction($source, $callee);
        if (in_array($name, ['get_class', 'get_parent_class'], true) && Syntax::arguments($source, $node) === []
            && $source->phpVersion->isAtLeast(PHPVersion::fromParts(8, 3))) {
            $context->report(Issue::new("{$name}() without an argument is deprecated since PHP 8.3; pass the object", $callee->span));
        }
    }
}
