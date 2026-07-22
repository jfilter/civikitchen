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

    /**
     * The failure this rule exists for: eight repos declared a min_coverage
     * while CI ran `phpunit --coverage-text`, which prints a percentage and
     * always exits 0. A floor nothing enforces reads like a gate and stops
     * nothing.
     */
    public function testADeclaredFloorWithoutCkcoverageFails(): void
    {
        $context = $this->repo([
            '.ckconform' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: phpunit --coverage-text tests/phpunit\n",
        ]);
        $this->assertFails($this->run_(new CiCoverageCheck(), $context), 'it never fails');
    }

    public function testADeclaredFloorWithCkcoveragePasses(): void
    {
        $context = $this->repo([
            '.ckconform' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    /** Delegating to the shared CI runs ckcoverage, though the token is not local. */
    public function testCallingTheSharedCiCountsAsRunningCkcoverage(): void
    {
        $context = $this->repo([
            '.ckconform' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@main\n    with:\n      key: x\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    /** No floor declared: reporting-only coverage is still acceptable. */
    public function testWithoutAFloorReportingCoverageIsEnough(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: phpunit --coverage-text\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }
}
