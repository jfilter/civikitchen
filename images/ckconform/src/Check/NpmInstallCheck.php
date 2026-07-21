<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * 'npm install' can rewrite the lockfile on a version drift; 'npm ci' refuses
 * and fails instead, which is what makes the lockfile actually binding in CI.
 */
final class NpmInstallCheck implements Check
{
    public function name(): string
    {
        return 'npm-install';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach ($context->workflows() as $workflow) {
            $contents = $context->read($workflow) ?? '';
            if (preg_match('/(^|\s)npm install(\s|$)/m', $contents)) {
                $reporter->warn("CI runs 'npm install' — use 'npm ci' so the lockfile is binding");

                return;
            }
        }
    }
}
