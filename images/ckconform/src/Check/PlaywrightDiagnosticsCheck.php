<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A Playwright suite that leaves nothing behind when it fails.
 *
 * A browser test that fails in CI is a stack trace and a screenshot of nothing.
 * Without a trace there is no way to see what the page actually looked like, and
 * re-running locally often will not reproduce it. Three separate gaps produced
 * exactly that here, each of which looked configured:
 *
 *   - `trace: 'on-first-retry'` records nothing for the FIRST failure. With
 *     retries disabled it never records at all, and where retries are on, a
 *     flaky test that passes on the second attempt leaves a trace nobody reads.
 *     `retain-on-failure` keeps one for every attempt that actually failed.
 *   - No reporter configured means no `playwright-report/` directory exists —
 *     and two workflows were archiving that exact path, so every upload was
 *     empty and no one noticed.
 *   - No upload step at all: the artefacts are written inside the runner and
 *     thrown away with it.
 */
final class PlaywrightDiagnosticsCheck implements Check
{
    public function name(): string
    {
        return 'playwright-diagnostics';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $configs = array_values(array_filter(
            $context->trackedFiles(),
            static fn (string $f): bool
                => preg_match('/^playwright\.config\.(ts|js|mjs)$/', basename($f)) === 1
        ));
        if ($configs === []) {
            return;
        }

        $problems = [];
        foreach ($configs as $config) {
            $body = $context->read($config) ?? '';
            if (preg_match("/trace:\s*'retain-on-failure'/", $body) !== 1) {
                $problems[] = $config . ': no retain-on-failure trace';
            }
            if (preg_match('/reporter:/', $body) !== 1) {
                $problems[] = $config . ': no reporter, so no playwright-report/ is written';
            }
        }

        // Recording artefacts inside the runner is pointless if nothing collects
        // them, so the two halves are checked together.
        if ($this->runsPlaywright($context) && !$this->uploadsArtefacts($context)) {
            $problems[] = 'CI runs playwright but no workflow uploads the report';
        }

        if ($problems !== []) {
            $reporter->fail('playwright leaves nothing to debug with: ' . implode('; ', $problems));
        } else {
            $reporter->ok('playwright records a trace and CI keeps it');
        }
    }

    private function runsPlaywright(Context $context): bool
    {
        foreach ($context->workflows() as $workflow) {
            if (str_contains($context->read($workflow) ?? '', 'playwright')) {
                return true;
            }
        }

        return false;
    }

    private function uploadsArtefacts(Context $context): bool
    {
        foreach ($context->workflows() as $workflow) {
            $body = $context->read($workflow) ?? '';
            if (str_contains($body, 'upload-artifact')
                && (str_contains($body, 'playwright-report') || str_contains($body, 'test-results'))) {
                return true;
            }
        }

        return false;
    }
}
