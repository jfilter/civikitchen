<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CommittedArtifactCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CommittedArtifactCheckTest extends CheckTestCase
{
    public function testFailsWhenNodeModulesIsCommittedAtTheTopLevel(): void
    {
        $context = $this->repo(['node_modules/some-dep/index.js' => ''], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: node_modules',
        );
    }

    public function testFailsWhenVendorIsCommittedNested(): void
    {
        $context = $this->repo(['ext/vendor/autoload.php' => ''], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: vendor',
        );
    }

    public function testFailsWhenThePhpunitCacheFileItselfIsCommitted(): void
    {
        $context = $this->repo(['.phpunit.result.cache' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: .phpunit.result.cache',
        );
    }

    public function testReportsEachArtifactInOrder(): void
    {
        $context = $this->repo([
            '.phpunit.result.cache' => '{}',
            'node_modules/dep/index.js' => '',
            'vendor/autoload.php' => '',
        ], git: true);
        $reporter = $this->run_(new CommittedArtifactCheck(), $context);
        self::assertSame([
            'build/cache artifact committed: .phpunit.result.cache',
            'build/cache artifact committed: node_modules',
            'build/cache artifact committed: vendor',
        ], $reporter->messages('FAIL'));
    }

    public function testPassesWhenNoneAreCommitted(): void
    {
        $context = $this->repo(['src/Foo.php' => '<?php'], git: true);
        $this->assertSilent($this->run_(new CommittedArtifactCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['node_modules/some-dep/index.js' => '']);
        $this->assertSilent($this->run_(new CommittedArtifactCheck(), $context));
    }
}
