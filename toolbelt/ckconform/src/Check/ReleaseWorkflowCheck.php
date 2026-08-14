<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Without a release there is no immutable ref: a consumer can pin nothing but a
 * branch, which moves under it, and no site ever installs a verified archive.
 *
 * A warning, not a failure. Adoption is one caller workflow per repo and the
 * pipeline is deliberately not template-managed (docs/extension-releases.md),
 * so failing here would turn the fleet red for a state every unadopted repo is
 * in — the exact fleet round that decision avoids.
 */
final class ReleaseWorkflowCheck implements Check
{
    public function name(): string
    {
        return 'release-workflow';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $declared = $context->policyValue('release');
        if ($declared !== null) {
            // Only the documented form opts out, and the reason is not
            // optional: an exemption nobody has to justify is how a rule ends
            // up declared away everywhere.
            if (preg_match('/^none\s+--\s+\S/', $declared) === 1) {
                $reporter->ok("no releases — declared deliberate in .ckconform ({$declared})");

                return;
            }
            $reporter->fail("unrecognised release= policy '{$declared}' — only 'release=none -- <reason>' opts out");

            return;
        }

        if ($context->callsSharedRelease()) {
            return;
        }

        $reporter->warn(
            'no release workflow (' . Context::SHARED_RELEASE . ') — nothing cuts a tagged, verified archive, '
            . 'so a consumer has no immutable ref to pin and installs a moving branch instead; '
            . 'see docs/extension-releases.md'
        );
    }
}
