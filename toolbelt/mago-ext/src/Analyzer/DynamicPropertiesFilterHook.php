<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * Drops `missing-magic-method` for a documented `@property` of a class that
 * allows dynamic properties itself or through an ancestor, as every CiviCRM DAO
 * does through DB_DataObject: PHP reads and writes such a property without __get/__set.
 * Upstream: carthage-software/mago#2483.
 */
final class DynamicPropertiesFilterHook implements IssueFilterHook
{
    private const PROPERTY_NOTE = '/^The class `([^`]+)` has a `@property` tag /';

    public function getCodes(): array
    {
        return ['missing-magic-method'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        foreach ($context->issue->notes as $note) {
            if (preg_match(self::PROPERTY_NOTE, $note, $match) === 1) {
                return self::allowsDynamicProperties($context, $match[1]) ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
            }
        }

        return IssueFilterDecision::Keep;
    }

    private static function allowsDynamicProperties(IssueFilterContext $context, string $name): bool
    {
        $class = $context->codebase->getClass(strtolower($name));
        if ($class === null) {
            return false;
        }
        foreach ([$class, ...array_filter($context->codebase->getMultipleClasses($class->parentClasses))] as $member) {
            foreach ($member->attributes as $attribute) {
                if (strtolower(ltrim($attribute->name, '\\')) === 'allowdynamicproperties') {
                    return true;
                }
            }
        }

        return false;
    }
}
