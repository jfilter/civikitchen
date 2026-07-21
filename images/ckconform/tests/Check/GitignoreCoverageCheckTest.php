<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\GitignoreCoverageCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class GitignoreCoverageCheckTest extends CheckTestCase
{
    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['phpunit.xml.dist' => '<phpunit/>', '.gitignore' => '']);
        $this->assertSilent($this->run_(new GitignoreCoverageCheck(), $context));
    }

    /** A missing .gitignore is GitignoreCheck's finding, not a duplicate here. */
    public function testSilentWithoutAGitignore(): void
    {
        $context = $this->repo(['phpunit.xml.dist' => '<phpunit/>'], git: true);
        $this->assertSilent($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testDemandsThePhpunitCacheWhenThereIsAPhpunitConfig(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '.phpunit.result.cache');
    }

    public function testPassesOnceTheCacheIsIgnored(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => ".phpunit.result.cache\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }

    /**
     * A PHP-only extension must never be nagged about node_modules — noise is
     * how a checker teaches people to stop reading its output.
     */
    public function testNodeModulesIsNotDemandedWithoutAPackageJson(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => ".phpunit.result.cache\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testNodeModulesIsDemandedOnceThereIsAPackageJson(): void
    {
        $context = $this->repo([
            'package.json' => '{"name":"x"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'node_modules/');
    }

    public function testTsbuildinfoIsDemandedOnlyForATypescriptRepo(): void
    {
        $withTs = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $withTs), '*.tsbuildinfo');
    }

    public function testVendorIsDemandedWhenComposerJsonExists(): void
    {
        $context = $this->repo([
            'composer.json' => '{"name":"x/y"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'vendor/');
    }

    /** A commented-out entry ignores nothing. */
    public function testACommentedEntryDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "# .phpunit.result.cache\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testEveryDemandedArtifactIsListedTogether(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'composer.json' => '{"name":"x/y"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $reporter = $this->run_(new GitignoreCoverageCheck(), $context);
        $message = implode("\n", $reporter->messages('FAIL'));
        self::assertStringContainsString('.phpunit.result.cache', $message);
        self::assertStringContainsString('vendor/', $message);
    }

    /**
     * The exact pattern that let inflow track a build cache: TypeScript names
     * the file after its tsconfig, so 'frontend/.tsbuildinfo' matches nothing.
     * The first version of this check accepted it — the very bug it was written
     * to catch.
     */
    public function testTheBrokenTsbuildinfoPatternIsNotAccepted(): void
    {
        $context = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\nfrontend/.tsbuildinfo\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '*.tsbuildinfo');
    }

    /** A negation un-ignores; it must not read as coverage. */
    public function testANegationIsNotCoverage(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "!.phpunit.result.cache\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '.phpunit.result.cache');
    }

    /** A nested cache has to be covered too, not just one at the root. */
    public function testANestedTsbuildinfoCounts(): void
    {
        $context = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\n*.tsbuildinfo\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }
}
