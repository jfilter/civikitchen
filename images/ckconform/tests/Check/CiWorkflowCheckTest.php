<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CiWorkflowCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CiWorkflowCheckTest extends CheckTestCase
{
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

    public function testWarnsWhenNoLintStepIsPresent(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  build:\n    steps:\n      - run: echo hi\n",
        ]);
        $reporter = $this->run_(new CiWorkflowCheck(), $context);
        $this->assertWarns($reporter, 'CI has no lint step (cklint/phpcs)');
    }
}
