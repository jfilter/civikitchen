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
 * only way out is `release: none` with a reason. In a repository of several
 * extensions the job whose working_directory is this extension builds it, and a
 * publish job needing that job releases every extension under one tag.
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

        $callers = $context->scopedJobsCalling(Context::SHARED_RELEASE);
        if (count($callers) > 1) {
            $reporter->fail(
                'more than one job calls ' . Context::SHARED_RELEASE . ' (' . implode(', ', $callers)
                . ') — one tag push would publish the release twice; keep the managed .github/workflows/release.yml'
            );

            return;
        }
        if ($callers !== []) {
            if ($context->isMonorepo()) {
                $this->judgeLockstep($context, $reporter, $callers[0]);
            }

            return;
        }
        if ($context->isMonorepo()) {
            $reporter->fail(
                'no job calls ' . Context::SHARED_RELEASE . ' with working_directory: ' . $context->extensionDirectory()
                . ' — the repository\'s release leaves this extension out; run ckinit --update on the repository root, '
                . 'or declare release: none with a reason; see docs/extension-releases.md'
            );

            return;
        }

        $reporter->fail(
            'no release workflow (' . Context::SHARED_RELEASE . ') — nothing cuts a tagged, verified archive, '
            . 'so a consumer has no immutable ref to pin and installs a moving branch instead; '
            . 'run ckinit --update, or declare release: none with a reason; see docs/extension-releases.md'
        );
    }

    /** One tag, one release: this extension's job only builds, a publish job needs it. */
    private function judgeLockstep(Context $context, Reporter $reporter, string $label): void
    {
        $at = (int) strrpos($label, ':');
        [$workflow, $name] = [substr($label, 0, $at), substr($label, $at + 1)];
        $stage = self::input($context->scopedJobs()[$label], 'stage') ?? 'release';
        if ($stage !== 'build') {
            $reporter->fail(
                "{$label} runs stage: " . (is_scalar($stage) ? (string) $stage : '?') . ' — in a repository of several '
                . 'extensions each extension\'s job runs stage: build, and one stage: publish job releases them together'
            );

            return;
        }
        foreach ($context->jobsCalling(Context::SHARED_RELEASE)[$workflow] ?? [] as $job) {
            if (self::input($job, 'stage') === 'publish' && in_array($name, (array) ($job['needs'] ?? []), true)) {
                return;
            }
        }
        $reporter->fail(
            "no stage: publish job in {$workflow} needs {$name} — this extension's archive would be missing from the release"
        );
    }

    /** @param array<mixed> $job */
    private static function input(array $job, string $name): mixed
    {
        return is_array($job['with'] ?? null) ? ($job['with'][$name] ?? null) : null;
    }
}
