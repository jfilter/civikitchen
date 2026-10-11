<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Docblocks\Docblock;
use CiviKitchen\Mago\Options;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * `@required` rejects a missing param before an action runs; on the actions
 * listed in --external-actions that must be a verdict instead. Inert without the
 * list. An entry with a `\` matches the qualified class name, one without the short name.
 */
final class NoRequiredOnExternalActionRule implements Rule
{
    private const MESSAGE = '@required is forbidden on the externally reachable action %s — enforce requiredness as a rejected '
        . 'verdict in the validation method, never as an APIv4 exception (a proxy HTTP 500 is retried forever)';

    /** @var list<string> */
    private readonly array $externalActions;

    public function __construct(Options $options)
    {
        $this->externalActions = array_map(static fn (string $entry): string => ltrim($entry, '\\'), $options->list('external-actions') ?? []);
    }

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/no-required-on-external-action',
            name: 'No @required on external actions',
            description: 'Externally reachable APIv4 actions answer a missing param with a verdict, not an exception.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Class_],
        );
    }

    public function lint(LintContext $context): void
    {
        if ($this->externalActions === []) {
            return;
        }
        $source = $context->file;
        $name = null;
        $headerEnd = $context->node->span->start;
        foreach ($source->getChildren($context->node) as $child) {
            if ($child->kind === NodeKind::ClassLikeMember) {
                break;
            }
            $name ??= $child->kind === NodeKind::LocalIdentifier ? $child : null;
            $headerEnd = $child->span->end;
        }
        $bodyStart = strpos($source->contents, '{', $headerEnd);
        if ($name === null || $bodyStart === false || !$this->isExternal($source->getText($name), (string) $source->getResolvedName($name)?->name)) {
            return;
        }
        // The innermost named class decides; a class declared inside this one is judged on its own.
        $nested = array_filter($source->getDescendants($context->node, NodeKind::Class_), static fn (Node $class): bool => $class->id !== $context->node->id);

        foreach (Docblock::all($source) as $docblock) {
            if ($docblock->span->start < $bodyStart || $docblock->span->end > $context->node->span->end) {
                continue;
            }
            foreach ($nested as $class) {
                if ($class->span->contains($docblock->span)) {
                    continue 2;
                }
            }
            foreach ($docblock->tags as $tag) {
                if (strtolower($tag['name']) === '@required') {
                    $context->report(Issue::new(sprintf(self::MESSAGE, $source->getText($name)), $tag['span']));
                }
            }
        }
    }

    private function isExternal(string $className, string $qualified): bool
    {
        foreach ($this->externalActions as $entry) {
            if (strcasecmp($entry, str_contains($entry, '\\') ? $qualified : $className) === 0) {
                return true;
            }
        }

        return false;
    }
}
