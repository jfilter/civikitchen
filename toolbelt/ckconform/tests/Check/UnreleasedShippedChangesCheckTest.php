<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\UnreleasedShippedChangesCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class UnreleasedShippedChangesCheckTest extends CheckTestCase
{
    private const RELEASE_CALLER = "name: Release\njobs:\n  release:\n    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n";

    public function testWarnsWhenShippedCodeHasBeenUnreleasedTooLong(): void
    {
        $context = $this->tagged();
        $this->write('Civi/Feature.php', '<?php // the fix nobody gets');
        $this->gitCommit('feature', '2020-01-01T00:00:00Z');
        $this->assertWarns(
            $this->run_(new UnreleasedShippedChangesCheck(), $context),
            'shipped code has been unreleased for',
        );
    }

    public function testSilentWhenOnlyTheDevelopmentLayerChanged(): void
    {
        $context = $this->tagged();
        $this->write('tests/phpunit/FeatureTest.php', '<?php');
        $this->write('.github/workflows/ci.yml', "name: CI\n");
        $this->write('phpunit.xml.dist', '<phpunit/>');
        $this->gitCommit('tests only', '2020-01-01T00:00:00Z');
        $this->assertSilent($this->run_(new UnreleasedShippedChangesCheck(), $context));
    }

    public function testSilentWhileTheChangeIsStillYoung(): void
    {
        $context = $this->tagged();
        $this->write('Civi/Feature.php', '<?php');
        $this->gitCommit('feature');
        $this->assertSilent($this->run_(new UnreleasedShippedChangesCheck(), $context));
    }

    public function testSilentWithNothingSinceTheTag(): void
    {
        $this->assertSilent($this->run_(new UnreleasedShippedChangesCheck(), $this->tagged()));
    }

    public function testTheRepoMayRaiseTheThreshold(): void
    {
        $context = $this->tagged();
        $this->write('.ckconform', "max_unreleased_days=9999\n");
        $this->write('Civi/Feature.php', '<?php');
        $this->gitCommit('feature', '2020-01-01T00:00:00Z');
        $this->assertSilent($this->run_(new UnreleasedShippedChangesCheck(), $context));
    }

    public function testTheRepoMayLowerTheThreshold(): void
    {
        $context = $this->tagged();
        $this->write('.ckconform', "max_unreleased_days=1\n");
        $this->write('Civi/Feature.php', '<?php');
        $this->gitCommit('feature', date('c', time() - 5 * 86400));
        $this->assertWarns($this->run_(new UnreleasedShippedChangesCheck(), $context), 'unreleased for 5 days');
    }

    public function testAThresholdThatIsNotANumberIsAFinding(): void
    {
        $context = $this->tagged();
        $this->write('.ckconform', "max_unreleased_days=one month\n");
        $this->gitCommit('policy');
        $this->assertFails(
            $this->run_(new UnreleasedShippedChangesCheck(), $context),
            'max_unreleased_days must be a whole number of days',
        );
    }

    public function testTheRepoDecidesWhatShipsThroughDistInclude(): void
    {
        // dist_include=tests puts the test suite back into the archive, so a
        // change to it is a change an install gets.
        $context = $this->tagged();
        $this->write('.ckconform', "dist_include=tests\n");
        $this->write('tests/phpunit/FeatureTest.php', '<?php');
        $this->gitCommit('tests only', '2020-01-01T00:00:00Z');
        $this->assertWarns($this->run_(new UnreleasedShippedChangesCheck(), $context), 'shipped code has been unreleased');
    }

    public function testWarnsUnevaluatedOnAShallowClone(): void
    {
        $context = $this->tagged();
        $this->gitShallow();
        $this->assertWarns(
            $this->run_(new UnreleasedShippedChangesCheck(), $context),
            'unreleased-shipped-changes not evaluated: shallow clone',
        );
    }

    public function testWarnsUnevaluatedWhenAnAdoptedRepoHasNoTag(): void
    {
        $context = $this->repo([
            '.github/workflows/release.yml' => self::RELEASE_CALLER,
            'Civi/Thing.php' => '<?php',
        ], git: true);
        $this->gitCommit('initial', '2020-01-01T00:00:00Z');
        $this->assertWarns(
            $this->run_(new UnreleasedShippedChangesCheck(), $context),
            'unreleased-shipped-changes not evaluated: no v* tag',
        );
    }

    public function testSilentWhenTheRepoHasNeitherTagsNorAReleaseWorkflow(): void
    {
        $context = $this->repo(['Civi/Thing.php' => '<?php'], git: true);
        $this->gitCommit('initial', '2020-01-01T00:00:00Z');
        $this->assertSilent($this->run_(new UnreleasedShippedChangesCheck(), $context));
    }

    /** A repo whose HEAD is tagged v1.0.0 and has the release caller. */
    private function tagged(): Context
    {
        $context = $this->repo([
            '.github/workflows/release.yml' => self::RELEASE_CALLER,
            'Civi/Thing.php' => '<?php',
        ], git: true);
        $this->gitCommit('release 1.0.0', '2020-01-01T00:00:00Z');
        $this->gitTag('v1.0.0');

        return $context;
    }
}
