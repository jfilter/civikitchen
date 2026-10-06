<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ConfigWithoutRunnerCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ConfigWithoutRunnerCheckTest extends CheckTestCase
{
    public function testSilentWithoutWorkflows(): void
    {
        $context = $this->repo(['phpstan.neon.dist' => 'parameters:'], git: true);
        $this->assertSilent($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** The classic case: a phpstan config no workflow ever invokes. */
    public function testAPhpstanConfigNobodyRunsFails(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => 'parameters:',
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpcs\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpstan.neon.dist');
    }

    public function testAnInvokedConfigPasses(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => 'parameters:',
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpstan analyse\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** ckcoverage runs phpunit, so it counts as the phpunit step. */
    public function testCkcoverageCountsAsThePhpunitRunner(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** A phpcs.xml.dist that no job runs — style and the footgun sniffs go unenforced. */
    public function testAPhpcsConfigNobodyRunsFails(): void
    {
        $context = $this->repo([
            'phpcs.xml.dist' => '<ruleset/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: phpstan analyse\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpcs.xml.dist');
    }

    /** The cklint wrapper counts as running phpcs, as does phpcs directly. */
    public function testCklintOrPhpcsCountsAsThePhpcsRunner(): void
    {
        foreach (['cklint --all', 'phpcs'] as $runner) {
            $context = $this->repo([
                'phpcs.xml.dist' => '<ruleset/>',
                '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: {$runner}\n",
            ], git: true);
            $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
        }
    }

    /**
     * The indirection that matters in practice: CI runs `npm run test`, and the
     * script is what names vitest. Without resolving it this would be a false
     * positive, and noise is how a checker gets ignored.
     */
    public function testAnNpmScriptCountsAsInvokingTheTool(): void
    {
        $context = $this->repo([
            'vitest.config.ts' => 'export default {}',
            'package.json' => '{"name":"x","scripts":{"test":"vitest run"}}',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: npm run test\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    public function testAnUnreferencedNpmScriptDoesNotCount(): void
    {
        $context = $this->repo([
            'vitest.config.ts' => 'export default {}',
            'package.json' => '{"name":"x","scripts":{"test":"vitest run"}}',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: npm run build\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'vitest.config.ts');
    }

    /** A second phpunit config only one repo remembers to run. */
    public function testASecondPhpunitConfigNeedsItsOwnStep(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'phpunit-unit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /**
     * A step described in a comment is not a step. one repo's workflow explains
     * why its phpunit job was retired, and that prose alone satisfied the first
     * version of this check.
     */
    public function testAToolNamedOnlyInACommentDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n"
                . "      # the phpunit job used to live here; see the notes\n"
                . "      - run: phpcs\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit.xml.dist');
    }

    /**
     * Delegating to the shared CI runs phpcs, phpstan and phpunit, so their
     * configs are not orphans — even though none of those tokens is in the repo.
     */
    public function testTheSharedCiSatisfiesPhpcsPhpstanAndPhpunit(): void
    {
        $context = $this->repo([
            'phpcs.xml.dist' => '<ruleset/>',
            'phpstan.neon.dist' => 'parameters:',
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@main\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** But it does not run playwright, so a playwright config is still an orphan. */
    public function testTheSharedCiDoesNotCoverPlaywright(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => 'export default {}',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@main\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'playwright.config.ts');
    }

    /** @param array<string, string> $files */
    private function runnerRepo(string $run, array $files): \CiviKitchen\Ckconform\Context
    {
        $files['.github/workflows/ci.yml'] = "jobs:\n  t:\n    runs-on: ubuntu-24.04\n    steps:\n      - run: {$run}\n";

        return $this->repo($files, git: true);
    }

    private const PHP_CONFIGS = [
        'phpcs.xml.dist' => '<ruleset/>',
        'phpstan.neon.dist' => 'parameters:',
        'phpunit.xml.dist' => '<phpunit/>',
    ];

    public function testCkCiRunsEveryPhpConfig(): void
    {
        $context = $this->runnerRepo('docker compose exec -T app ck ci', self::PHP_CONFIGS);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testCkLintAndCkCoverageRunPhpcsAndPhpunit(): void
    {
        $context = $this->runnerRepo("ck lint --all && ck coverage tests/phpunit", [
            'phpcs.xml.dist' => '<ruleset/>',
            'phpunit.xml.dist' => '<phpunit/>',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testACkCiLimitedToLintLeavesPhpstanUnrun(): void
    {
        $context = $this->runnerRepo('ck ci --only cklint,ckconform', self::PHP_CONFIGS);
        $reporter = $this->run_(new ConfigWithoutRunnerCheck(), $context);
        $this->assertFails($reporter, 'phpstan.neon.dist (no phpstan step), phpunit.xml.dist (no phpunit step)');
        self::assertStringNotContainsString('phpcs.xml.dist', $reporter->render());
    }

    public function testADirectoryNamedAfterAToolIsNoRunner(): void
    {
        $context = $this->runnerRepo('ls tests/phpunit/', ['phpunit.xml.dist' => '<phpunit/>']);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit.xml.dist (no phpunit step)');
    }

    public function testNpmTestResolvesTheTestScript(): void
    {
        $context = $this->runnerRepo('npm test', [
            'package.json' => '{"scripts": {"test": "vitest run"}}',
            'vitest.config.ts' => 'export default {};',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testNpmTestRunningSomethingElseLeavesVitestUnrun(): void
    {
        $context = $this->runnerRepo('npm test', [
            'package.json' => '{"scripts": {"test": "eslint .", "unit": "vitest run"}}',
            'vitest.config.ts' => 'export default {};',
        ]);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'vitest.config.ts');
    }

    public function testComposerScriptsResolveIncludingReferences(): void
    {
        $context = $this->runnerRepo('composer test', [
            'composer.json' => '{"scripts": {"test": ["@unit"], "unit": "phpunit"}}',
            'phpunit.xml.dist' => '<phpunit/>',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }
}
