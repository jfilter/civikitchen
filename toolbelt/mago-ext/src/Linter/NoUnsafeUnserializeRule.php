<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Support\Syntax;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * unserialize() must decide `allowed_classes` explicitly; an options argument
 * that is not an array literal of literal keys cannot be read and is accepted.
 */
final class NoUnsafeUnserializeRule implements Rule
{
    private const MESSAGE = 'unserialize() without an allowed_classes option instantiates arbitrary classes from the payload; '
        . 'pass [\'allowed_classes\' => FALSE] (or an explicit class list)';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/no-unsafe-unserialize',
            name: 'No unsafe unserialize',
            description: 'unserialize() decides allowed_classes explicitly.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::FunctionCall, NodeKind::FunctionPartialApplication],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $callee = Syntax::unwrap($source, $source->getChildren($context->node)[0]);
        $name = Syntax::globalFunctionName($source, $callee);
        $arguments = Syntax::arguments($source, $context->node);
        if ($name === 'unserialize') {
            $options = Syntax::parameter($arguments, 2, 'options');
            if ($options === null || self::lacksAllowedClasses($source, $options)) {
                $context->report(Issue::new(self::MESSAGE, $callee->span));
            }
            return;
        }
        if ($name === null || !isset(Syntax::CALLBACK_POSITIONS[$name])) {
            return;
        }
        $callback = Syntax::parameter($arguments, Syntax::CALLBACK_POSITIONS[$name], 'callback');
        $text = $callback === null || $callback['spread'] || $callback['value'] === null ? null : Syntax::stringLiteral($source, $callback['value']);
        if ($text !== null && strcasecmp(Syntax::stripQuotes($text), 'unserialize') === 0) {
            $context->report(Issue::new(self::MESSAGE, $callback['value']->span));
        }
    }

    /**
     * An array literal whose keys are all literals, none of them `allowed_classes`.
     *
     * @param array{name: ?string, value: ?Node, spread: bool, node: Node} $options
     */
    private static function lacksAllowedClasses(SourceFile $source, array $options): bool
    {
        $array = $options['value'];
        if ($options['spread'] || $array === null || !in_array($array->kind, [NodeKind::Array, NodeKind::LegacyArray], true)) {
            return false;
        }
        foreach ($source->getChildren($array) as $element) {
            $variant = $source->getChildren($element)[0] ?? null;
            if ($variant?->kind === NodeKind::VariadicArrayElement) {
                return false;
            }
            if ($variant?->kind !== NodeKind::KeyValueArrayElement) {
                continue;
            }
            $key = Syntax::unwrap($source, $source->getChildren($variant)[0]);
            $literal = $key->kind === NodeKind::Literal ? $source->getChildren($key)[0] ?? null : null;
            if ($literal?->kind === NodeKind::LiteralInteger) {
                continue;
            }
            $text = Syntax::stringLiteral($source, $key);
            if ($text === null || Syntax::stripQuotes($text) === 'allowed_classes') {
                return false;
            }
        }

        return true;
    }
}
