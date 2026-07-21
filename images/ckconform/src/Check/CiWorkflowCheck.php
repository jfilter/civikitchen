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

        $reporter->ok('CI workflow present');

        foreach ($workflows as $workflow) {
            $contents = $context->read($workflow) ?? '';
            if (str_contains($contents, 'cklint') || str_contains($contents, 'phpcs')) {
                return;
            }
        }

        $reporter->warn('CI has no lint step (cklint/phpcs)');
    }
}
