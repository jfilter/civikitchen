<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * No .gitignore means build output and vendor/ have no guard rail keeping
 * them out of the next commit — see CommittedArtifactCheck for what lands
 * there once nothing stops it.
 */
final class GitignoreCheck implements Check
{
    public function name(): string
    {
        return 'gitignore';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        if (!$context->exists('.gitignore')) {
            $reporter->fail('no .gitignore (build output and vendor/ land in git)');
        }
    }
}
