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
 * including `.yaml`.
 */
final class CiCoverageCheck implements Check
{
    public function name(): string
    {
        return 'ci-coverage';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        $workflows = $context->workflows();
        if ($workflows === []) {
            return;
        }

        // The shared CI runs ckcoverage; a repo that calls it is measured.
        $ran = $context->callsSharedCi() ? 'ckcoverage' : '';
        foreach ($workflows as $workflow) {
            if ($ran === 'ckcoverage') {
                break;
            }
            $contents = $context->read($workflow) ?? '';
            if (preg_match('/(^|[^\w-])ckcoverage([^\w-]|$)/', $contents) === 1) {
                $ran = 'ckcoverage';
                break;
            }
            if (str_contains($contents, '--coverage-')) {
                $ran = 'report-only';
            }
        }

        // A declared floor with nothing to enforce it reads like a gate and stops
        // nothing: `phpunit --coverage-text` prints a percentage and always exits 0.
        if ($context->policyValue('min_coverage') !== null && $ran !== 'ckcoverage') {
            $reporter->fail(
                'civikitchen.yaml sets min_coverage but no workflow runs ckcoverage — '
                . ($ran === 'report-only'
                    ? 'phpunit --coverage-* only reports, it never fails'
                    : 'nothing measures coverage at all')
            );

            return;
        }

        if ($ran !== '') {
            return;
        }

        $reporter->warn('CI runs tests without coverage — add ckcoverage (or phpunit --coverage-text)');
    }

}
