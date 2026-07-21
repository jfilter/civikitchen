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

    /**
     * on-first-retry records nothing for the first failure — and nothing at all
     * when retries are off.
     */
    public function testOnFirstRetryIsNotEnough(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => str_replace('retain-on-failure', 'on-first-retry', self::GOOD),
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    /**
     * The real case: two workflows archived tests/e2e/playwright-report/ while no
     * reporter was configured, so the directory never existed and every upload
     * was empty.
     */
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
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no workflow uploads');
    }

    /** A config with no workflow running it is somebody else's finding. */
    public function testNoUploadDemandedWhenCiDoesNotRunPlaywright(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpcs\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }
}
