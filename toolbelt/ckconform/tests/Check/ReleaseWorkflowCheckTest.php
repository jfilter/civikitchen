<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ReleaseWorkflowCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ReleaseWorkflowCheckTest extends CheckTestCase
{
    public function testWarnsWhenNoWorkflowCallsTheSharedReleasePipeline(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n",
        ]);
        $this->assertWarns($this->run_(new ReleaseWorkflowCheck(), $context), 'no release workflow');
    }

    public function testWarnsWhenThereAreNoWorkflowsAtAll(): void
    {
        $this->assertWarns($this->run_(new ReleaseWorkflowCheck(), $this->repo([])), 'immutable ref');
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
}
