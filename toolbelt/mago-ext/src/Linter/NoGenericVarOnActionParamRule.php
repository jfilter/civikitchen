<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Docblocks\Docblock;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Core reads an APIv4 action param's last @var at runtime and throws "Unknown
 * parameter type" on anything but array, bool, string, int, float, mixed (|null)
 * or an array{} shape. Params: protected, non-static, not `_*`, not `version`.
 */
final class NoGenericVarOnActionParamRule implements Rule
{
    private const ACCEPTED_TYPES = ['array', 'bool', 'string', 'int', 'float', 'mixed'];

    private const MESSAGE = 'APIv4 action param $%s has @var "%s", which core cannot validate at runtime ("Unknown parameter type") '
        . '— use array, bool, string, int, float or mixed (optionally |null) and put the precise type in @phpstan-var';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/no-generic-var-on-action-param',
            name: 'No generic @var on APIv4 action params',
            description: 'APIv4 action params carry a @var type core can validate at runtime.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Class_],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        if (!self::isAction($source, $context->node)) {
            return;
        }
        foreach (self::members($source, $context->node, NodeKind::Property) as $property) {
            $plain = $source->getChildren($property)[0] ?? null;
            if ($plain?->kind !== NodeKind::PlainProperty) {
                continue;
            }
            $modifiers = [];
            foreach ($source->getChildren($plain) as $child) {
                if ($child->kind === NodeKind::Modifier) {
                    $modifiers[] = strtolower($source->getText($child));
                }
            }
            if (!in_array('protected', $modifiers, true) || in_array('static', $modifiers, true)) {
                continue;
            }
            foreach ($source->getDescendants($plain, NodeKind::DirectVariable) as $variable) {
                if ($source->getParent($variable)?->kind !== NodeKind::PropertyAbstractItem
                    && $source->getParent($variable)?->kind !== NodeKind::PropertyConcreteItem) {
                    continue;
                }
                $this->check($context, $variable);
            }
        }
    }

    private function check(LintContext $context, Node $variable): void
    {
        $source = $context->file;
        $name = substr($source->getText($variable), 1);
        if ($name === 'version' || str_starts_with($name, '_')) {
            return;
        }
        $varTag = null;
        foreach (Docblock::above($source, $variable->span->start)?->tags ?? [] as $tag) {
            if ($tag['name'] === '@var') {
                $varTag = $tag;
            }
        }
        if ($varTag === null || str_starts_with($varTag['string'], 'array{')) {
            return;
        }
        $word = preg_split('/\s+/', $varTag['string'])[0];
        $types = array_diff(explode('|', strtolower($word)), ['null']);
        if (array_diff($types, self::ACCEPTED_TYPES) === []) {
            return;
        }
        $context->report(Issue::new(sprintf(self::MESSAGE, $name, $word), $varTag['stringSpan'] ?? $varTag['span']));
    }

    /**
     * A core action parent (`Civi\Api4\Action\…`, `Civi\Api4\Generic\…Action`), a
     * Civi\Api4 class extending a `…Action`, or a `_run(Result)` method.
     */
    private static function isAction(SourceFile $source, Node $class): bool
    {
        $extends = null;
        $name = null;
        foreach ($source->getChildren($class) as $child) {
            if ($child->kind === NodeKind::Extends) {
                $extends = $source->getFirstDescendant($child, NodeKind::Identifier);
            } elseif ($child->kind === NodeKind::LocalIdentifier && $name === null) {
                $name = $child;
            }
        }
        if ($extends === null || $name === null) {
            return false;
        }
        $resolved = strtolower((string) $source->getResolvedName($extends)?->name);
        if (str_starts_with($resolved, 'civi\\api4\\action\\')
            || (str_starts_with($resolved, 'civi\\api4\\generic\\') && str_ends_with($resolved, 'action'))) {
            return true;
        }
        $qualified = (string) $source->getResolvedName($name)?->name;
        $namespace = str_contains($qualified, '\\') ? substr($qualified, 0, (int) strrpos($qualified, '\\')) : '';
        if (($namespace === 'Civi\\Api4' || str_starts_with($namespace, 'Civi\\Api4\\')) && str_ends_with($source->getText($extends), 'Action')) {
            return true;
        }
        foreach (self::members($source, $class, NodeKind::Method) as $method) {
            $methodName = null;
            foreach ($source->getChildren($method) as $part) {
                $methodName ??= $part->kind === NodeKind::LocalIdentifier ? $part : null;
            }
            if ($methodName === null || strcasecmp($source->getText($methodName), '_run') !== 0) {
                continue;
            }

            return strcasecmp((string) self::firstParameterClass($source, $method), 'Civi\\Api4\\Generic\\Result') === 0;
        }

        return false;
    }

    /** The class a method's first parameter is typed with (`?X` included), or null. */
    private static function firstParameterClass(SourceFile $source, Node $method): ?string
    {
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind !== NodeKind::FunctionLikeParameterList) {
                continue;
            }
            $parameter = $source->getChildren($child)[0] ?? null;
            $hint = null;
            foreach ($parameter === null ? [] : $source->getChildren($parameter) as $part) {
                if ($part->kind === NodeKind::Hint) {
                    $hint = $part;
                }
            }
            $inner = $hint === null ? null : ($source->getChildren($hint)[0] ?? null);
            if ($inner?->kind === NodeKind::NullableHint) {
                $inner = $source->getChildren($source->getChildren($inner)[0])[0] ?? null;
            }

            return $inner?->kind === NodeKind::Identifier ? $source->getResolvedName($inner)?->name : null;
        }

        return null;
    }

    /** @return list<Node> the class's own members of one kind */
    private static function members(SourceFile $source, Node $class, NodeKind $kind): array
    {
        $members = [];
        foreach ($source->getChildren($class) as $child) {
            if ($child->kind === NodeKind::ClassLikeMember) {
                foreach ($source->getChildren($child) as $member) {
                    if ($member->kind === $kind) {
                        $members[] = $member;
                    }
                }
            }
        }

        return $members;
    }
}
