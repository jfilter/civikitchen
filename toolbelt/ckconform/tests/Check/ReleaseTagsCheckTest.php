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

    public function testFailsAndNamesEveryUntaggedVersion(): void
    {
        $context = $this->history([
            '0.1.0' => 'v0.1.0',
            '0.2.0' => null,
            '0.3.0' => null,
            '0.4.0' => 'v0.4.0',
        ]);
        self::assertStringContainsString(
            'info.xml <version> moved past 0.2.0, 0.3.0 and none of v0.2.0, v0.3.0 exists — the bump was '
            . 'committed, the tag never cut, so nothing installable carries those versions; '
            . 'see docs/extension-releases.md',
            $this->run_(new ReleaseTagsCheck(), $context)->render(),
        );
    }

    /**
     * A commit that deletes info.xml is in `git log -- info.xml` but has no
     * blob to read a version from — the history skips it instead of treating
     * the gap as a version of its own.
     */
    public function testSkipsCommitsThatCarryNoInfoXml(): void
    {
        $context = $this->history(['1.0.0' => 'v1.0.0']);
        unlink($context->root . '/info.xml');
        $this->gitCommit('drop the extension manifest');
        $this->bump('1.1.0');
        $this->bump('1.2.0');

        $reporter = $this->run_(new ReleaseTagsCheck(), $context);
        $this->assertFails($reporter, 'moved past 1.1.0 and no v1.1.0 exists');
        self::assertStringNotContainsString('moved past , ', $reporter->render());
    }

    /** A bump that was taken back: the version it passed through still needs its tag. */
    public function testReportsARevertedVersionOnce(): void
    {
        $context = $this->history(['1.0.0' => 'v1.0.0', '1.1.0' => null]);
        $this->bump('1.0.0');

        $reporter = $this->run_(new ReleaseTagsCheck(), $context);
        $this->assertFails($reporter, 'moved past 1.1.0 and no v1.1.0 exists');
        self::assertStringNotContainsString('1.1.0, 1.1.0', $reporter->render());
    }

    /** Versions are compared as text: nothing here parses semver. */
    public function testHandlesANonSemverVersion(): void
    {
        $context = $this->history(['1.0-beta1' => null, '1.0.0' => 'v1.0.0']);
        $this->assertFails(
            $this->run_(new ReleaseTagsCheck(), $context),
            'moved past 1.0-beta1 and no v1.0-beta1 exists',
        );

        $tagged = $this->history(['1.0-beta1' => 'v1.0-beta1', '1.0.0' => 'v1.0.0']);
        $this->assertSilent($this->run_(new ReleaseTagsCheck(), $tagged));
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
            if ($context === null) {
                $context = $this->repo(
                    ['info.xml' => $this->infoXml(extra: "<version>{$version}</version>")],
                    git: true,
                );
                $this->gitCommit("release {$version}");
                if ($tag !== null) {
                    $this->gitTag($tag);
                }

                continue;
            }
            $this->bump((string) $version, $tag);
        }
        self::assertInstanceOf(Context::class, $context);

        return $context;
    }

    /** One more release commit on the repo history() started. */
    private function bump(string $version, ?string $tag = null): void
    {
        $this->write('info.xml', $this->infoXml(extra: "<version>{$version}</version>"));
        $this->gitCommit("release {$version}");
        if ($tag !== null) {
            $this->gitTag($tag);
        }
    }
}
