<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Without an explicit top-level `permissions:` block a workflow's job token
 * inherits the repository default, which is write-all on older repos and
 * orgs. A lint job does not need to be able to push.
 *
 * The block must start in column 0: a job-level `permissions:` (indented,
 * scoped to one job) narrows that job but says nothing about the others in
 * the same file, so it does not satisfy this check — intentionally, matching
 * the bash original's `grep -q '^permissions:'`.
 */
final class WorkflowPermissionsCheck implements Check
{
    public function name(): string
    {
        return 'workflow-permissions';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->workflows() as $workflow) {
            $contents = $context->read($workflow) ?? '';
            if (!preg_match('/^permissions:/m', $contents)) {
                $reporter->warn(
                    "{$workflow} declares no 'permissions:' block — the job token inherits the repo default"
                );
            }
        }
    }
}
