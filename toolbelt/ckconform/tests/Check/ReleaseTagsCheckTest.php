<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ReleaseTagsCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ReleaseTagsCheckTest extends CheckTestCase
{
    public function testPassesWhenEveryEarlierVersionWasTagged(): void
    {
        $context = $this->history(['0.1.0' => 'v0.1.0', '0.2.0' => 'v0.2.0', '0.3.0' => null]);
        $this->assertSilent($this->run_(new ReleaseTagsCheck(), $context));
    }

    public function testFailsAndNamesTheVersionThatWasNeverTagged(): void
    {
        $context = $this->history(['0.1.0' => 'v0.1.0', '0.2.0' => null, '0.3.0' => null]);
        $reporter = $this->run_(new ReleaseTagsCheck(), $context);
        $this->assertFails($reporter, 'moved past 0.2.0');
        self::assertStringContainsString('no v0.2.0 exists', $reporter->render());
    }

    public function testFailsWhenTheRepoBumpedVersionsAndHasNoTagAtAll(): void
    {
        $context = $this->history(['0.7.0' => null, '0.8.0' => null]);
        $this->assertFails($this->run_(new ReleaseTagsCheck(), $context), 'no v* tag at all');
    }

    public function testPassesForAFreshRepoAtItsFirstVersion(): void
    {
        $context = $this->history(['0.1.0' => null]);
        $this->assertSilent($this->run_(new ReleaseTagsCheck(), $context));
    }

    public function testPassesWhenTheCurrentVersionIsTheOnlyUntaggedOne(): void
    {
        // The window between the bump commit and the tag push, which
        // release-tag-coherence owns.
        $context = $this->history(['1.0.0' => 'v1.0.0', '1.1.0' => null]);
        $this->assertSilent($this->run_(new ReleaseTagsCheck(), $context));
    }

    public function testTheRepoMayDeclareThatItCutsNoReleases(): void
    {
        $context = $this->history(['0.1.0' => null, '0.2.0' => null]);
        $this->write('civikitchen.yaml', $this->policyFixture("release=none -- configuration-only extension\n"));
        $this->gitCommit('policy');
        $this->assertSilent($this->run_(new ReleaseTagsCheck(), $context));
    }

    public function testWarnsUnevaluatedOnAShallowClone(): void
    {
        $context = $this->history(['0.1.0' => null, '0.2.0' => null]);
        $this->gitShallow();
        $this->assertWarns($this->run_(new ReleaseTagsCheck(), $context), 'release-tags not evaluated: shallow clone');
    }

    /**
     * The state a default `actions/checkout` produces, made the way CI makes
     * it: a depth-1 clone has neither the tags nor the info.xml history, so
     * without the shallow guard the rule would report a clean repo.
     */
    public function testWarnsUnevaluatedOnADepthOneCloneOfARepoThatSkippedATag(): void
    {
        $source = $this->history(['0.1.0' => 'v0.1.0', '0.2.0' => null, '0.3.0' => null]);
        $this->assertFails($this->run_(new ReleaseTagsCheck(), $source), 'moved past 0.2.0');

        $clone = sys_get_temp_dir() . '/ckconform-clone-' . bin2hex(random_bytes(6));
        exec(sprintf(
            'GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null git clone -q --depth 1 %s %s 2>/dev/null',
            escapeshellarg('file://' . $source->root),
            escapeshellarg($clone),
        ), $output, $status);
        self::assertSame(0, $status, 'the fixture clone failed');

        try {
            $this->assertWarns(
                $this->run_(new ReleaseTagsCheck(), new Context($clone)),
                'release-tags not evaluated: shallow clone',
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($clone));
        }
    }

    /**
     * A repo whose info.xml carried each version in turn, tagged where the map
     * names a tag. The last entry is the current version.
     *
     * @param array<string, string|null> $versions version => tag or null
     */
    private function history(array $versions): Context
    {
        $context = null;
        foreach ($versions as $version => $tag) {
            $info = $this->infoXml(extra: "<version>{$version}</version>");
            if ($context === null) {
                $context = $this->repo(['info.xml' => $info], git: true);
            } else {
                $this->write('info.xml', $info);
            }
            $this->gitCommit("release {$version}");
            if ($tag !== null) {
                $this->gitTag($tag);
            }
        }
        self::assertInstanceOf(Context::class, $context);

        return $context;
    }
}
