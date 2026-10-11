<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Guards;
use CiviKitchen\Mago\Options;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * With --compatibility (ckcompat), drops a newer function, class or method
 * the file guards with function_exists(), class_exists() or method_exists(),
 * or behind a check for a PHP version above the floor.
 */
final class GuardedAvailabilityFilterHook implements IssueFilterHook
{
    private const CODES = ['unavailable-function', 'unavailable-class-like', 'unavailable-method'];

    private readonly bool $active;

    public function __construct(Options $options)
    {
        $this->active = $options->string('compatibility') !== null;
    }

    public function getCodes(): array
    {
        return self::CODES;
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (!$this->active || preg_match('/`([^`]+)`/', $context->issue->message, $symbol) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $contents = $context->contents;
        $version = Guards::version($contents);
        if ($version !== null && version_compare($version, $context->phpVersion->major() . '.' . $context->phpVersion->minor(), '>')) {
            return IssueFilterDecision::Remove;
        }
        [$class, $method] = array_pad(explode('::', $symbol[1], 2), 2, '');
        $guarded = match ($context->issue->code) {
            'unavailable-function' => Guards::function($contents, $symbol[1]),
            // A class guard vouches for a method only when the class itself is newer than the floor.
            'unavailable-method' => Guards::method($contents, $class, $method) || (Guards::classLike($contents, $class) && self::newer($context, $class)),
            default => Guards::classLike($contents, $class),
        };

        return $guarded ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    private static function newer(IssueFilterContext $context, string $class): bool
    {
        $ranges = $context->codebase->getClassLike($class)?->availableVersions ?? [];
        foreach ($ranges as $range) {
            if ($range->minimum === null || $context->phpVersion->isAtLeast($range->minimum)) {
                return false;
            }
        }

        return $ranges !== [];
    }
}
