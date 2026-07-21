<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\LockfileCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class LockfileCheckTest extends CheckTestCase
{
    public function testFailsWhenPackageJsonHasNoTrackedLockfile(): void
    {
        $context = $this->repo(['package.json' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'package.json has no tracked lockfile (builds are unreproducible)',
        );
    }

    public function testPassesWhenALockfileIsTrackedNextToTheManifest(): void
    {
        $context = $this->repo([
            'package.json' => '{}',
            'package-lock.json' => '{}',
        ], git: true);
        $this->assertPasses($this->run_(new LockfileCheck(), $context));
    }

    public function testChecksTheLockfileNextToANestedManifest(): void
    {
        $context = $this->repo(['frontend/package.json' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'frontend/package.json has no tracked lockfile (builds are unreproducible)',
        );
    }

    public function testANestedManifestWithItsOwnYarnLockPasses(): void
    {
        $context = $this->repo([
            'frontend/package.json' => '{}',
            'frontend/yarn.lock' => '',
        ], git: true);
        $this->assertPasses($this->run_(new LockfileCheck(), $context));
    }

    /** A lockfile belonging to a sibling package does not count. */
    public function testALockfileInAnotherDirectoryDoesNotCount(): void
    {
        $context = $this->repo([
            'frontend/package.json' => '{}',
            'backend/yarn.lock' => '',
        ], git: true);
        $this->assertFails($this->run_(new LockfileCheck(), $context), 'frontend/package.json');
    }

    public function testAPackageJsonInsideNodeModulesIsIgnored(): void
    {
        $context = $this->repo(['node_modules/some-dep/package.json' => '{}'], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonWithNoRequireDoesNotNeedALockfile(): void
    {
        $context = $this->repo(['composer.json' => '{"name": "acme/ext"}'], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonRequiringOnlyPhpDoesNotNeedALockfile(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1"}}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonWithARealDependencyNeedsATrackedLock(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "guzzlehttp/guzzle": "^7.0"}}',
        ], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'composer.json declares dependencies but composer.lock is not tracked',
        );
    }

    public function testComposerJsonWithARealDependencyAndATrackedLockPasses(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "guzzlehttp/guzzle": "^7.0"}}',
            'composer.lock' => '{}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testGitignoreExcludingALockfileFails(): void
    {
        $context = $this->repo(['.gitignore' => "yarn.lock\n"], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes yarn.lock — lockfiles belong in the repo',
        );
    }

    public function testGitignoreExcludingALockfileInASubdirectoryFails(): void
    {
        $context = $this->repo(['.gitignore' => "/build/composer.lock\n"], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes composer.lock — lockfiles belong in the repo',
        );
    }

    /**
     * A commented-out entry must not trigger the rule, even though it ends in
     * "/pnpm-lock.yaml" — the shape the bash regex's unanchored alternative
     * would have matched.
     */
    public function testACommentedGitignoreLineIsNotAMatch(): void
    {
        $context = $this->repo(['.gitignore' => "# keep an eye on /pnpm-lock.yaml\n"], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['package.json' => '{}']);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }
}
