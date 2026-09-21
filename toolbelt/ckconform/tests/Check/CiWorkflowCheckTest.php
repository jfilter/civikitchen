<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CiWorkflowCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CiWorkflowCheckTest extends CheckTestCase
{
    private const MONOREPO_CALLER = "name: CI\njobs:\n  example:\n"
        . "    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n"
        . "    with:\n      working_directory: example\n";

    public function testFailsWithoutAnyWorkflow(): void
    {
        $reporter = $this->run_(new CiWorkflowCheck(), $this->repo([]));
        $this->assertFails($reporter, 'no CI workflow (.github/workflows/)');
    }

    public function testOkWithNoWarnWhenAWorkflowRunsPhpcs(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  lint:\n    steps:\n      - run: vendor/bin/phpcs\n",
        ]);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        self::assertSame(['CI workflow present'], $reporter->messages('ok'));
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('warn'));
    }

    public function testCallingTheSharedCiCountsAsALintStep(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@main\n",
        ]);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('warn'));
    }

    public function testWarnsWhenNoLintStepIsPresent(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  build:\n    steps:\n      - run: echo hi\n",
        ]);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertWarns($reporter, 'CI has no lint step (cklint/phpcs)');
    }

    public function testUsesRepositoryRootWorkflowForNestedExtension(): void
    {
        $context = $this->monorepoExtension([
            '.github/workflows/ci.yml' => self::MONOREPO_CALLER,
        ], []);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('warn'));
    }

    public function testFailsWhenNoRootJobRunsThisExtension(): void
    {
        $context = $this->monorepoExtension([
            '.github/workflows/ci.yml' => str_replace('example', 'base', self::MONOREPO_CALLER),
        ], [], ['Civi/Neighbour.php' => '<?php']);
        $this->assertFails(
            $this->run_(new CiWorkflowCheck(), $context),
            'no workflow job sets working_directory: example',
        );
    }

    public function testPassesOnTheGeneratedRootWorkflowWithItsManagedMarkers(): void
    {
        $context = $this->monorepoExtension([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n# END CIVIKITCHEN MANAGED header\n"
                . "  # BEGIN CIVIKITCHEN MANAGED job-example\n  example:\n"
                . "    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n"
                . "    with:\n      working_directory: example\n"
                . "  # END CIVIKITCHEN MANAGED job-example\n      playwright: true\n",
        ], [], ['Civi/Neighbour.php' => '<?php']);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('warn'));
    }
    /** A neighbour's shared-CI job says nothing about whether this extension is linted. */
    public function testWarnsWhenOnlyANeighboursJobRunsLint(): void
    {
        $context = $this->monorepoExtension([
            '.github/workflows/ci.yml' => str_replace('example', 'base', self::MONOREPO_CALLER)
                . "  example:\n    runs-on: ubuntu-latest\n    defaults:\n      run:\n"
                . "        working-directory: example\n    steps:\n      - run: vendor/bin/phpunit\n",
        ], [], ['Civi/Neighbour.php' => '<?php']);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'CI has no lint step (cklint/phpcs)');
    }

    public function testAMentionOfTheSharedCiInACommentIsNoLintStep(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "# was: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\nname: CI\njobs:\n  test:\n    runs-on: ubuntu-latest\n    steps:\n      - run: phpunit\n",
        ]);
        $this->assertWarns($this->run_(new CiWorkflowCheck(), $context), 'no lint step');
    }
}
