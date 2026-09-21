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
 * The caller is a template-managed file, so `ckinit --update` adopts it; the
 * only way out is `release: none` with a reason. A repository of several
 * extensions has no release caller yet and is not evaluated.
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
                $reporter->ok("no releases — declared deliberate in civikitchen.yaml ({$declared})");

                return;
            }
            $reporter->fail("unrecognised release= policy '{$declared}' — only 'release=none -- <reason>' opts out");

            return;
        }

        if ($context->isMonorepo()) {
            $reporter->warn("{$this->name()} not evaluated: releases of multi-extension repositories are not supported yet");

            return;
        }

        $callers = $context->scopedJobsCalling(Context::SHARED_RELEASE);
        if (count($callers) > 1) {
            $reporter->fail(
                'more than one job calls ' . Context::SHARED_RELEASE . ' (' . implode(', ', $callers)
                . ') — one tag push would publish the release twice; keep the managed .github/workflows/release.yml'
            );

            return;
        }
        if ($callers !== []) {
            return;
        }

        $reporter->fail(
            'no release workflow (' . Context::SHARED_RELEASE . ') — nothing cuts a tagged, verified archive, '
            . 'so a consumer has no immutable ref to pin and installs a moving branch instead; '
            . 'run ckinit --update, or declare release: none with a reason; see docs/extension-releases.md'
        );
    }
}
