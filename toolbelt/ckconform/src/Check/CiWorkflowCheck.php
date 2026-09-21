<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * No CI means every conformance rule that only fires "on push" runs on a
 * laptop at best, which is to say: sometimes.
 */
final class CiWorkflowCheck implements Check
{
    public function name(): string
    {
        return 'ci-workflow';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $workflows = $context->workflows();
        if ($workflows === []) {
            $reporter->fail('no CI workflow (.github/workflows/)');

            return;
        }

        // Reported here and nowhere else: one defect, one finding. Every
        // workflow-reading check judges the caller job that names this
        // extension's directory, and with no such job they would all be silent.
        $scopeFailure = $context->workflowScopeFailure();
        if ($scopeFailure !== null) {
            $reporter->fail($scopeFailure);

            return;
        }

        $reporter->ok('CI workflow present');

        // Judged on the jobs that run this extension: a neighbour's lint step
        // says nothing about whether this extension is linted.
        if ($context->scopedCallsShared(Context::SHARED_CI)) {
            return;
        }
        foreach ($context->scopedWorkflows() as $body) {
            if (str_contains($body, 'cklint') || str_contains($body, 'phpcs')) {
                return;
            }
        }

        $reporter->warn('CI has no lint step (cklint/phpcs)');
    }
}
