<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * Drops index and array-cast findings on core's APIv4 Result: it extends
 * ArrayObject without template types, so mago rejects every row access.
 */
final class Api4ResultFilterHook implements IssueFilterHook
{
    private const PATTERNS = [
        'invalid-array-index' => '/ used for array access on `Civi\\\\Api4\\\\Generic\\\\Result`\.$/',
        'invalid-type-cast' => '/^Object of type `Civi\\\\Api4\\\\Generic\\\\Result` cast to `array`\./',
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
