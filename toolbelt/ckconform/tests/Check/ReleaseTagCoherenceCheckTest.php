<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ReleaseTagCoherenceCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ReleaseTagCoherenceCheckTest extends CheckTestCase
{
    private const RELEASE_CALLER = "name: Release\njobs:\n  release:\n    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n";

    public function testFailsWhenInfoXmlIsAheadOfTheNewestTag(): void
    {
        $context = $this->released('1.1.0', 'v1.0.0');
        $this->assertFails(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'info.xml <version> 1.1.0 is ahead of the newest tag v1.0.0',
        );
    }

    public function testFailsWhenInfoXmlIsBelowAnExistingTag(): void
    {
        $context = $this->released('0.1.0', 'v1.0.0');
        $this->assertFails(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'info.xml <version> 0.1.0 is below the tag v1.0.0',
        );
        $this->assertFails($this->run_(new ReleaseTagCoherenceCheck(), $context), 'release a version above 1.0.0');
    }

    public function testFailsWhenAStrayPreReleaseTagOutranksThePreRelease(): void
    {
        $context = $this->released('0.1.0-alpha3', 'v0.1.0-alpha2');
        $this->gitTag('v1.0.0-alpha1');
        $this->assertFails(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'info.xml <version> 0.1.0-alpha3 is below the tag v1.0.0-alpha1',
        );
    }

    public function testSilentWhenTheRepoDeclaresNoReleases(): void
    {
        // A fixture below the root of a released repository sees that
        // repository's tags; a repo that publishes nothing has none to order.
        $context = $this->released('0.1.0', 'v1.0.0');
        $this->write('civikitchen.yaml', $this->policyFixture("release=none -- fixture, never published\n"));
        $this->assertSilent($this->run_(new ReleaseTagCoherenceCheck(), $context));
    }

    public function testComparesTheHighestTagNotTheNearestOne(): void
    {
        $context = $this->released('1.0.0', 'v2.0.0');
        $this->write('Civi/Later.php', '<?php');
        $this->gitCommit('later');
        $this->gitTag('v1.0.0');
        $this->assertFails($this->run_(new ReleaseTagCoherenceCheck(), $context), 'below the tag v2.0.0');
    }

    public function testPassesWhenTheVersionIsAboveEveryTag(): void
    {
        $context = $this->released('1.0.0', 'v1.0.0');
        $this->gitTag('v1.0.0-alpha.10');
        $this->gitTag('v1.0.0-alpha.2');
        $this->assertPasses($this->run_(new ReleaseTagCoherenceCheck(), $context));
    }

    public function testOrdersNumericPreReleaseIdentifiersNumerically(): void
    {
        $context = $this->released('1.0.0-alpha.2', 'v1.0.0-alpha.10');
        $this->assertFails(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'info.xml <version> 1.0.0-alpha.2 is below the tag v1.0.0-alpha.10',
        );
    }

    public function testIgnoresATagThatIsNotSemver(): void
    {
        $context = $this->released('1.0.0', 'v1.0.0');
        $this->gitTag('v9.0');
        $this->gitTag('v10.0.0.1');
        $this->assertSilent($this->run_(new ReleaseTagCoherenceCheck(), $context));
    }

    public function testSilentWhenTheVersionMatchesTheNewestTag(): void
    {
        $this->assertSilent($this->run_(new ReleaseTagCoherenceCheck(), $this->released('1.0.0', 'v1.0.0')));
    }

    public function testTheNewestTagWinsOverAnOlderOne(): void
    {
        $context = $this->released('1.1.0', 'v1.0.0');
        $this->write('Civi/Later.php', '<?php');
        $this->gitCommit('bump');
        $this->gitTag('v1.1.0');
        $this->assertSilent($this->run_(new ReleaseTagCoherenceCheck(), $context));
    }

    public function testWarnsUnevaluatedOnAShallowClone(): void
    {
        $context = $this->released('1.1.0', 'v1.0.0');
        $this->gitShallow();
        $this->assertWarns(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'release-tag-coherence not evaluated: shallow clone',
        );
    }

    public function testWarnsUnevaluatedWhenAnAdoptedRepoHasNoTag(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<version>1.0.0</version>'),
            '.github/workflows/release.yml' => self::RELEASE_CALLER,
        ], git: true);
        $this->gitCommit('initial');
        $this->assertWarns(
            $this->run_(new ReleaseTagCoherenceCheck(), $context),
            'release-tag-coherence not evaluated: no v* tag',
        );
    }

    public function testWarnsUnevaluatedOutsideAGitCheckout(): void
    {
        $context = $this->repo(['info.xml' => $this->infoXml(extra: '<version>1.0.0</version>')]);
        $this->assertWarns($this->run_(new ReleaseTagCoherenceCheck(), $context), 'not a git checkout');
    }

    public function testSilentWhenTheRepoHasNeitherTagsNorAReleaseWorkflow(): void
    {
        // release-workflow owns that verdict; saying it again per rule would
        // put three findings on one state.
        $context = $this->repo(['info.xml' => $this->infoXml(extra: '<version>1.0.0</version>')], git: true);
        $this->gitCommit('initial');
        $this->assertSilent($this->run_(new ReleaseTagCoherenceCheck(), $context));
    }

    /** A repo at <version> whose newest tag is <tag>, with the release caller. */
    private function released(string $version, string $tag): Context
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "<version>{$version}</version>"),
            '.github/workflows/release.yml' => self::RELEASE_CALLER,
            'Civi/Thing.php' => '<?php',
        ], git: true);
        $this->gitCommit('initial');
        $this->gitTag($tag);

        return $context;
    }
}
