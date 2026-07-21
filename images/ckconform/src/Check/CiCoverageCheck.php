<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A CI job that runs the suite but never measures it tells you the tests still
 * pass and nothing about whether they still cover anything.
 *
 * Scope is Context::workflows(), like every other workflow check: recursive and
 * including `.yaml`. The bash predecessor globbed `.github/workflows/*.yml`
 * directly in that directory, because that is what the original globbed and the
 * golden output across the consuming repos was captured from.
 */
final class CiCoverageCheck implements Check
{
    public function name(): string
    {
        return 'ci-coverage';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!is_dir($context->path('tests/phpunit'))) {
            return;
        }

        $workflows = $context->workflows();
        if ($workflows === []) {
            return;
        }

        foreach ($workflows as $workflow) {
            if (str_contains($context->read($workflow) ?? '', 'coverage')) {
                return;
            }
        }

        $reporter->warn('CI runs tests without coverage — add ckcoverage (or phpunit --coverage-text)');
    }

}
