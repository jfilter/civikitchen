<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Support\Syntax;
use CiviKitchen\Mago\Options;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A positional TRUE/FALSE literal says nothing at the call site; name it.
 * `ignore-calls` lists callees whose bool reads fine; a setter's sole argument is exempt.
 */
final class NameBooleanArgumentsRule implements Rule
{
    private const DEFAULT_IGNORE_CALLS = [
        'in_array', 'array_search', 'array_keys', 'json_decode', 'define', 'array_push', 'array_unshift',
        'array_fill', 'array_fill_keys', 'array_pad', 'addWhere', 'addHaving', 'addValue', 'set',
        'assertSame', 'assertEquals', 'assertNotSame', 'assertNotEquals',
    ];

    /** @var list<string> lower case */
    private readonly array $ignoreCalls;

    public function __construct(Options $options)
    {
        $this->ignoreCalls = array_map('strtolower', $options->list('ignore-calls') ?? self::DEFAULT_IGNORE_CALLS);
    }

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/name-boolean-arguments',
            name: 'Name boolean arguments',
            description: 'A literal TRUE/FALSE argument is passed by name.',
            defaultLevel: Level::Warning,
            defaultEnabled: true,
            targets: [
                NodeKind::FunctionCall, NodeKind::MethodCall, NodeKind::NullSafeMethodCall, NodeKind::StaticMethodCall,
                NodeKind::FunctionPartialApplication, NodeKind::MethodPartialApplication, NodeKind::StaticMethodPartialApplication,
                NodeKind::Instantiation, NodeKind::Attribute,
            ],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $callee = self::calleeName($source, $context->node);
        if ($callee === null || in_array(strtolower($callee), $this->ignoreCalls, true)) {
            return;
        }
        $arguments = Syntax::arguments($source, $context->node);
        if (preg_match('/^set[A-Z]/', $callee) === 1 && count($arguments) === 1 && !Syntax::hasTrailingComma($source, $context->node)) {
            return;
        }
        foreach ($arguments as $argument) {
            if ($argument['name'] !== null || $argument['spread'] || $argument['value'] === null
                || Syntax::booleanLiteral($source, $argument['value']) === null) {
                continue;
            }
            $literal = (string) Syntax::keywordLiteral($source, $argument['value']);
            $context->report(Issue::new(
                "Positional {$literal} says nothing at the call site — name the argument ({$callee}(… , flag: {$literal}))",
                $argument['value']->span,
            ));
        }
    }

    /** The callee as written: a function's or class's last name segment, or a method name. */
    private static function calleeName(SourceFile $source, Node $node): ?string
    {
        $children = $source->getChildren($node);
        $name = match ($node->kind) {
            NodeKind::FunctionCall, NodeKind::FunctionPartialApplication, NodeKind::Attribute => $children[0] ?? null,
            NodeKind::Instantiation => $children[1] ?? null,
            default => ($children[1] ?? null)?->kind === NodeKind::ClassLikeMemberSelector ? $source->getChildren($children[1])[0] ?? null : null,
        };
        if ($name === null) {
            return null;
        }
        $name = Syntax::unwrap($source, $name);
        if ($name->kind === NodeKind::Identifier) {
            $name = $source->getChildren($name)[0] ?? $name;
        }
        if (!in_array($name->kind, [NodeKind::LocalIdentifier, NodeKind::QualifiedIdentifier, NodeKind::FullyQualifiedIdentifier], true)) {
            return null;
        }
        $text = $source->getText($name);
        $slash = strrpos($text, '\\');

        return $slash === false ? $text : substr($text, $slash + 1);
    }
}
