<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * Drops mago's finding on the array cast of core's APIv4 Result: casting an
 * ArrayObject yields its rows, not its properties.
 */
final class Api4ResultFilterHook implements IssueFilterHook
{
    private const PATTERNS = [
        'invalid-type-cast' => '/^Object of type `(\$this\()?Civi\\\\Api4\\\\Generic\\\\Result(<[^`]*>)?(?(1)\))` cast to `array`\./',
    ];

    public function getCodes(): array
    {
        return array_keys(self::PATTERNS);
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $pattern = self::PATTERNS[$context->issue->code ?? ''] ?? null;

        return $pattern !== null && preg_match($pattern, $context->issue->message) === 1
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }
}
