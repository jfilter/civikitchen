<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Guards;
use CiviKitchen\Mago\Linter\Support\Syntax;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;

/**
 * What the declared PHP floor lacks where mago has no check of its own. Off by
 * default: ckcompat runs it with the file's PHP version set to the floor.
 */
final class PhpCompatibilityRule implements Rule
{
    /**
     * Functions mago has no availability data for and CiviCRM core does not
     * polyfill (core ships symfony/polyfill-php8x and -mbstring) => first PHP.
     */
    private const NEW_FUNCTIONS = [
        'mysqli_execute_query' => [8, 2],
        'posix_eaccess' => [8, 3],
        'posix_fpathconf' => [8, 3],
        'http_get_last_response_headers' => [8, 4],
        'intltz_get_iana_id' => [8, 4],
        'request_parse_body' => [8, 4],
        'curl_multi_get_handles' => [8, 5],
    ];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/php-compatibility',
            name: 'PHP floor compatibility',
            description: 'Syntax and functions the declared PHP floor lacks, where mago has no check of its own.',
            defaultLevel: Level::Error,
            defaultEnabled: false,
            targets: [NodeKind::FunctionCall, NodeKind::ClassConstantAccess],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $node = $context->node;
        $floor = $source->phpVersion;
        $shown = $floor->major() . '.' . $floor->minor();
        if ($node->kind === NodeKind::ClassConstantAccess) {
            foreach ($source->getDescendants($node, NodeKind::ClassLikeMemberExpressionSelector) as $selector) {
                if ($source->getParent($selector)?->kind === NodeKind::ClassLikeConstantSelector && !$floor->isAtLeast(PHPVersion::fromParts(8, 3))) {
                    $context->report(Issue::new("A dynamic class constant fetch `C::{\$name}` needs PHP 8.3, the floor is {$shown}; use constant()", $selector->span));
                }
            }
            return;
        }
        $callee = Syntax::unwrap($source, $source->getChildren($node)[0]);
        $name = Syntax::calledGlobalFunction($source, $callee);
        $since = self::NEW_FUNCTIONS[$name ?? ''] ?? null;
        $version = Guards::version($source->contents);
        if ($since !== null && !$floor->isAtLeast(PHPVersion::fromParts(...$since)) && !Guards::function($source->contents, (string) $name)
            && ($version === null || version_compare($version, implode('.', $since), '<'))) {
            $context->report(Issue::new("{$name}() exists since PHP " . implode('.', $since) . ", the floor is {$shown}", $callee->span));
        }
    }
}
