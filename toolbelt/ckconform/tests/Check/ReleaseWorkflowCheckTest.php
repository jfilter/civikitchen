<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ReleaseWorkflowCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ReleaseWorkflowCheckTest extends CheckTestCase
{
    public function testFailsWhenNoWorkflowCallsTheSharedReleasePipeline(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n",
        ]);
        $this->assertFails($this->run_(new ReleaseWorkflowCheck(), $context), 'no release workflow');
    }

    public function testFailsWhenThereAreNoWorkflowsAtAll(): void
    {
        $this->assertFails($this->run_(new ReleaseWorkflowCheck(), $this->repo([])), 'immutable ref');
    }

    public function testSilentWhenTheSharedReleasePipelineIsCalled(): void
    {
        $context = $this->repo([
            '.github/workflows/release.yml' => "name: Release\njobs:\n  release:\n    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n",
        ]);
        $this->assertSilent($this->run_(new ReleaseWorkflowCheck(), $context));
    }

    public function testADeclaredExemptionWithAReasonOptsOut(): void
    {
        $context = $this->repo(['__policy_fixture' => "release=none -- internal glue, never installed elsewhere\n"]);
        $reporter = $this->run_(new ReleaseWorkflowCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('warn'));
        self::assertStringContainsString('declared deliberate', implode('', $reporter->messages('ok')));
    }

    public function testAnExemptionWithoutAReasonIsItselfAFinding(): void
    {
        $context = $this->repo(['__policy_fixture' => "release=none\n"]);
        $this->expectException(\RuntimeException::class);
        $this->run_(new ReleaseWorkflowCheck(), $context);
    }

    public function testAnUnrecognisedPolicyValueDoesNotSilenceTheRule(): void
    {
        $context = $this->repo(['__policy_fixture' => "release=later -- we will get to it\n"]);
        $this->expectException(\RuntimeException::class);
        $this->run_(new ReleaseWorkflowCheck(), $context);
    }

    /** The root caller ckinit stamps for `base` and `example`, with $publishNeeds and $exampleStage. */
    private function rootReleaseCaller(string $publishNeeds = '[base, example]', string $exampleStage = 'build'): string
    {
        $job = static fn (string $directory, string $stage): string => "  {$directory}:\n"
            . "    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n"
            . "    with:\n      working_directory: {$directory}\n      stage: {$stage}\n";

        return "name: Release\njobs:\n" . $job('base', 'build') . $job('example', $exampleStage)
            . "  publish:\n    needs: {$publishNeeds}\n"
            . "    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n"
            . "    with:\n      stage: publish\n";
    }

    private function monorepoRelease(string $caller): \CiviKitchen\Ckconform\Context
    {
        return $this->monorepoExtension(
            ['.github/workflows/release.yml' => $caller],
            [],
            ['info.xml' => $this->infoXml(key: 'base')],
        );
    }

    public function testAMultiExtensionRepositoryPassesWithTheLockstepCaller(): void
    {
        $reporter = $this->run_(new ReleaseWorkflowCheck(), $this->monorepoRelease($this->rootReleaseCaller()));
        $this->assertSilent($reporter);
    }

    public function testAMultiExtensionRepositoryFailsWhenNoJobBuildsThisExtension(): void
    {
        $caller = "name: Release\njobs:\n  base:\n    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n"
            . "    with:\n      working_directory: base\n      stage: build\n";
        $this->assertFails(
            $this->run_(new ReleaseWorkflowCheck(), $this->monorepoRelease($caller)),
            'no job calls extension-release.yml with working_directory: example',
        );
    }

    public function testAMultiExtensionRepositoryWithoutAnyReleaseWorkflowFails(): void
    {
        $context = $this->monorepoExtension([], [], ['info.xml' => $this->infoXml(key: 'base')]);
        $this->assertFails($this->run_(new ReleaseWorkflowCheck(), $context), 'working_directory: example');
    }

    public function testAMultiExtensionJobThatPublishesOnItsOwnFails(): void
    {
        $this->assertFails(
            $this->run_(new ReleaseWorkflowCheck(), $this->monorepoRelease($this->rootReleaseCaller(exampleStage: 'release'))),
            'release.yml:example runs stage: release',
        );
    }

    public function testAMultiExtensionJobNoPublishJobNeedsFails(): void
    {
        $this->assertFails(
            $this->run_(new ReleaseWorkflowCheck(), $this->monorepoRelease($this->rootReleaseCaller('[base]'))),
            'needs example — this extension\'s archive would be missing from the release',
        );
    }

    public function testAMultiExtensionRepositoryCanStillOptOut(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['civikitchen.yaml' => $this->policyFixture("release=none -- internal glue, never installed elsewhere\n")],
        );
        $reporter = $this->run_(new ReleaseWorkflowCheck(), $context);
        self::assertSame([], $reporter->messages('warn'));
        self::assertStringContainsString('declared deliberate', implode('', $reporter->messages('ok')));
    }

    public function testAMentionInACommentIsNoCaller(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "# releases go through jfilter/civikitchen/.github/workflows/extension-release.yml@v1\nname: CI\njobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n",
        ]);
        $this->assertFails($this->run_(new ReleaseWorkflowCheck(), $context), 'no release workflow');
    }

    public function testFailsWhenTwoJobsCallTheSharedReleasePipeline(): void
    {
        $caller = "name: Release\njobs:\n  release:\n    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1\n";
        $context = $this->repo([
            '.github/workflows/release.yml' => $caller,
            '.github/workflows/publish.yml' => $caller,
        ]);
        $this->assertFails($this->run_(new ReleaseWorkflowCheck(), $context), 'more than one job calls extension-release.yml');
    }
}
