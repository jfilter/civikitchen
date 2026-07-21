<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PlaywrightDiagnosticsCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PlaywrightDiagnosticsCheckTest extends CheckTestCase
{
    private const GOOD = <<<'TS'
        export default {
          reporter: process.env.CI ? 'html' : 'list',
          use: { screenshot: 'only-on-failure', trace: 'retain-on-failure', video: 'retain-on-failure' },
        };
        TS;

    private const UPLOAD = <<<'YML'
        jobs:
          e2e:
            steps:
              - run: npx playwright test
              - uses: actions/upload-artifact@v4
                if: always()
                with:
                  path: playwright-report/
        YML;

    public function testSilentWithoutPlaywright(): void
    {
        $this->assertSilent($this->run_(new PlaywrightDiagnosticsCheck(), $this->repo([], git: true)));
    }

    public function testAFullySetUpSuitePasses(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testOnFirstRetryIsNotEnough(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => str_replace('retain-on-failure', 'on-first-retry', self::GOOD),
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    public function testAMissingReporterIsReported(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { use: { trace: 'retain-on-failure' } };",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter');
    }

    public function testRecordingWithoutUploadingIsPointless(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: npx playwright test\n",
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload its report');
    }

    /**
     * An upload with no `if:` is skipped precisely when a test failed — the one
     * run whose report is worth keeping.
     */
    public function testAnUploadWithoutIfAlwaysFails(): void
    {
        $noIf = str_replace("        if: always()\n", '', self::UPLOAD);
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $noIf,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'without if: always()');
    }

    public function testIfFailureIsAlsoAccepted(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => str_replace('if: always()', 'if: failure()', self::UPLOAD),
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /**
     * GitHub runners share no filesystem: an upload in a different job than the
     * Playwright run collects nothing.
     */
    public function testAnUploadInAnotherJobDoesNotCount(): void
    {
        $split = <<<'YML'
            jobs:
              e2e:
                steps:
                  - run: npx playwright test
              archive:
                steps:
                  - uses: actions/upload-artifact@v4
                    if: always()
                    with:
                      path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $split,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'same job');
    }

    public function testNoUploadDemandedWhenCiDoesNotRunPlaywright(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpcs\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /** A commented-out upload step must not satisfy the check. */
    public function testACommentedUploadDoesNotCount(): void
    {
        $commented = <<<'YML'
            jobs:
              e2e:
                steps:
                  - run: npx playwright test
                  # - uses: actions/upload-artifact@v4
                  #   with:
                  #     path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $commented,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload');
    }
}
