<?php

declare(strict_types=1);

namespace CiviKitchen\Mago;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;

/** A linter rule that skips files matching any of its excluded globs (workspace-relative, fnmatch). */
final class ScopedRule implements Rule
{
    /** @param list<string> $excludes */
    public function __construct(private readonly Rule $rule, private readonly array $excludes)
    {
    }

    public function getDefinition(): RuleDefinition
    {
        return $this->rule->getDefinition();
    }

    public function lint(LintContext $context): void
    {
        foreach ($this->excludes as $glob) {
            if (fnmatch($glob, $context->file->path)) {
                return;
            }
        }
        $this->rule->lint($context);
    }
}
