<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Every method and property states its visibility, without `var`, one property
 * per declaration: the Drupal standard's MethodScope and PropertyDeclaration.
 */
final class ExplicitVisibilityRule implements Rule
{
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/explicit-visibility',
            name: 'Explicit visibility',
            description: 'Methods and properties declare their visibility, one property per declaration, never with var.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Method, NodeKind::Property],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        if ($context->node->kind === NodeKind::Method) {
            $name = $source->getFirstDescendant($context->node, NodeKind::LocalIdentifier);
            if ($name !== null && !self::hasVisibility($source, $context->node)) {
                $context->report(Issue::new(sprintf('Visibility must be declared on method "%s"', $source->getText($name)), $name->span));
            }
            return;
        }
        $declaration = $source->getChildren($context->node)[0] ?? null;
        if ($declaration === null) {
            return;
        }
        $items = array_values(array_filter($source->getChildren($declaration), static fn (Node $child): bool => $child->kind === NodeKind::PropertyItem));
        foreach ($source->getChildren($declaration) as $child) {
            if ($child->kind === NodeKind::Keyword && strtolower($source->getText($child)) === 'var') {
                $context->report(Issue::new('The var keyword must not be used to declare a property', $child->span));
            }
        }
        if (isset($items[1])) {
            $context->report(Issue::new('There must not be more than one property declared per statement', $items[1]->span));
        }
        if (isset($items[0]) && !self::hasVisibility($source, $declaration)) {
            $variable = trim(explode('=', $source->getText($items[0]), 2)[0]);
            $context->report(Issue::new(sprintf('Visibility must be declared on property "%s"', $variable), $items[0]->span));
        }
    }

    /** A direct modifier public, protected or private, including PHP 8.4's `private(set)` forms. */
    private static function hasVisibility(SourceFile $source, Node $declaration): bool
    {
        foreach ($source->getChildren($declaration) as $child) {
            if ($child->kind === NodeKind::Modifier && preg_match('/^(public|protected|private)\b/i', $source->getText($child)) === 1) {
                return true;
            }
        }

        return false;
    }
}
