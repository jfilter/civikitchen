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
 * curl_setopt() and literal curl_setopt_array() options that turn off TLS peer or host verification.
 * Ported from amateescu/mago-drupal's drupal/curl-ssl-verify (MIT, LICENSE-mago-drupal.txt).
 */
final class CurlSslVerifyRule implements Rule
{
    private const OPTIONS = ['CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST'];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/curl-ssl-verify',
            name: 'Curl SSL verification',
            description: 'curl keeps CURLOPT_SSL_VERIFYPEER and CURLOPT_SSL_VERIFYHOST on.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::FunctionCall],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $name = Syntax::calledGlobalFunction($source, $source->getChildren($context->node)[0]);
        $arguments = Syntax::arguments($source, $context->node);
        if ($name === 'curl_setopt') {
            $option = Syntax::parameter($arguments, 2, 'option');
            $value = Syntax::parameter($arguments, 3, 'value');
            if ($option !== null && $value !== null && !$option['spread'] && self::option($source, $option['value']) !== null
                && $value['value'] !== null && self::isOff($source, $value['value'])) {
                self::report($context, $value['value']);
            }
            return;
        }
        $options = $name === 'curl_setopt_array' ? Syntax::parameter($arguments, 2, 'options') : null;
        $array = $options === null || $options['spread'] || $options['value'] === null ? null : self::unparenthesize($source, $options['value']);
        if ($array === null || !in_array($array->kind, [NodeKind::Array, NodeKind::LegacyArray], true)) {
            return;
        }
        // A later duplicate key wins before curl sees the array.
        $effective = [];
        foreach ($source->getChildren($array) as $element) {
            $pair = $source->getChildren($element)[0] ?? null;
            [$key, $value] = $pair?->kind === NodeKind::KeyValueArrayElement ? $source->getChildren($pair) + [1 => null] : [null, null];
            $option = $key === null ? null : self::option($source, $key);
            if ($option !== null && $value !== null) {
                $effective[$option] = $value;
            }
        }
        foreach ($effective as $value) {
            if (self::isOff($source, $value)) {
                self::report($context, $value);
            }
        }
    }

    private static function report(LintContext $context, Node $value): void
    {
        $context->report(Issue::new('Do not turn off TLS certificate verification: anyone on the path can then pose as the server.', $value->span)
            ->withHelp('Leave the option at its default; for a private CA, point CURLOPT_CAINFO at its certificate.'));
    }

    /** The verification option a node names, else null. */
    private static function option(SourceFile $source, ?Node $option): ?string
    {
        $option = $option === null ? null : self::unparenthesize($source, $option);
        $name = $option?->kind === NodeKind::ConstantAccess ? ltrim($source->getText($option), '\\') : null;

        return in_array($name, self::OPTIONS, true) ? $name : null;
    }

    private static function isOff(SourceFile $source, Node $value): bool
    {
        $value = self::unparenthesize($source, $value);
        if (Syntax::booleanValue($source, $value) === false) {
            return true;
        }
        $literal = $value->kind === NodeKind::Literal ? $source->getChildren($value)[0] ?? null : null;

        // Zero in any base: 0, 00, 0x0, 0b0, 0o0.
        return $literal?->kind === NodeKind::LiteralInteger
            && preg_match('/^0[xXbBoO]?0*$/', str_replace('_', '', $source->getText($literal))) === 1;
    }

    private static function unparenthesize(SourceFile $source, Node $node): Node
    {
        $node = Syntax::unwrap($source, $node);
        while ($node->kind === NodeKind::Parenthesized && ($inner = $source->getChildren($node)[0] ?? null) !== null) {
            $node = Syntax::unwrap($source, $inner);
        }

        return $node;
    }
}
