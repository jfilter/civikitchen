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
 * Reads of $_SERVER['REMOTE_ADDR']; writes, as tests seed a request, stay silent.
 * Ported from amateescu/mago-drupal's drupal/remote-address (MIT, LICENSE-mago-drupal.txt).
 */
final class RemoteAddressRule implements Rule
{
    /** Kinds between an access and the assignment or list that writes it. */
    private const WRAPPERS = [
        NodeKind::Expression,
        NodeKind::Parenthesized,
        NodeKind::Array,
        NodeKind::LegacyArray,
        NodeKind::List,
        NodeKind::ArrayElement,
        NodeKind::ValueArrayElement,
        NodeKind::KeyValueArrayElement,
    ];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/remote-address',
            name: 'Raw remote address',
            description: "The client address comes from CRM_Utils_System::ipAddress(), not \$_SERVER['REMOTE_ADDR'].",
            defaultLevel: Level::Error,
            defaultEnabled: true,
            // Program puts every access's parent chain into the snapshot, which isWrite() walks.
            targets: [NodeKind::ArrayAccess, NodeKind::Program],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        if ($context->node->kind !== NodeKind::ArrayAccess || !str_contains($source->getText($context->node), 'REMOTE_ADDR')) {
            return;
        }
        [$target, $key] = $source->getChildren($context->node) + [1 => null];
        $target = Syntax::unwrap($source, $target);
        if ($target->kind === NodeKind::Variable) {
            $target = Syntax::unwrap($source, $source->getChildren($target)[0] ?? $target);
        }
        $key = $key === null ? null : Syntax::stringLiteral($source, $key);
        if ($target->kind !== NodeKind::DirectVariable || $source->getText($target) !== '$_SERVER'
            || $key === null || Syntax::stripQuotes($key) !== 'REMOTE_ADDR' || self::isWrite($source, $context->node)) {
            return;
        }
        $context->report(Issue::new("Read the client address with CRM_Utils_System::ipAddress(), not \$_SERVER['REMOTE_ADDR'].", $context->node->span)
            ->withHelp('It asks the CMS, which honours reverse-proxy settings on Drupal and Backdrop, and maps IPv4-mapped IPv6 addresses.'));
    }

    /** Assignment target, unset() argument or destructuring target; a key or subscript is a read. */
    private static function isWrite(SourceFile $source, Node $node): bool
    {
        $child = $node;
        for ($parent = $source->getParent($child); $parent !== null; $parent = $source->getParent($child)) {
            $first = ($source->getChildren($parent)[0] ?? null)?->id === $child->id;
            if ($parent->kind === NodeKind::Assignment || $parent->kind === NodeKind::Unset) {
                return $parent->kind === NodeKind::Unset || $first;
            }
            if (($parent->kind === NodeKind::KeyValueArrayElement && $first) || ($parent->kind === NodeKind::ArrayAccess && !$first)) {
                return false;
            }
            if ($parent->kind !== NodeKind::ArrayAccess && !in_array($parent->kind, self::WRAPPERS, true)) {
                return false;
            }
            $child = $parent;
        }

        return false;
    }
}
