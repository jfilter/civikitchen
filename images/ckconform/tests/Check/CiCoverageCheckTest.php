<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CiCoverageCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CiCoverageCheckTest extends CheckTestCase
{
    public function testWarnsWhenCiRunsTestsWithoutCoverage(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    steps:\n      - run: cktest\n",
        ]);
        $this->assertWarns(
            $this->run_(new CiCoverageCheck(), $context),
            'CI runs tests without coverage — add ckcoverage (or phpunit --coverage-text)',
        );
    }

    public function testSilentWhenCkcoverageIsWired(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    steps:\n      - run: ckcoverage\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    /** Any one workflow mentioning coverage satisfies the check, as in bash. */
    public function testASecondWorkflowMayCarryCoverage(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/lint.yml' => "jobs:\n  lint:\n    steps:\n      - run: cklint\n",
            '.github/workflows/test.yml' => "jobs:\n  test:\n    steps:\n      - run: phpunit --coverage-text\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testSilentWithoutWorkflows(): void
    {
        $context = $this->repo(['tests/phpunit/SomeTest.php' => '<?php']);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testSilentWithoutATestDirectory(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  build:\n    steps:\n      - run: make\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }
}
