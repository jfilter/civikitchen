<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\TestBootstrapGuardCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class TestBootstrapGuardCheckTest extends CheckTestCase
{
    public function testSaysNothingWithoutATestsPhpunitDirectory(): void
    {
        $this->assertSilent($this->run_(new TestBootstrapGuardCheck(), $this->repo([])));
    }

    public function testFailsWhenTheBootstrapIsMissing(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php class SomeTest {}',
        ]);
        $this->assertFails($this->run_(new TestBootstrapGuardCheck(), $context), 'no tests/phpunit/bootstrap.php');
    }

    public function testFailsWhenAHeadlessSuiteHasAnUnguardedBootstrap(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php require_once __DIR__ . "/../../vendor/autoload.php";',
            'tests/phpunit/HeadlessTest.php' => '<?php class HeadlessTest implements HeadlessInterface {}',
        ]);
        $this->assertFails(
            $this->run_(new TestBootstrapGuardCheck(), $context),
            'lacks the TEST_DB_DSN guard — headless runs can wipe the dev DB',
        );
    }

    public function testPassesWhenTheGuardIsPresent(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php if (!getenv("TEST_DB_DSN")) { exit(1); }',
            'tests/phpunit/HeadlessTest.php' => '<?php class HeadlessTest implements HeadlessInterface {}',
        ]);
        $reporter = $this->run_(new TestBootstrapGuardCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(['test bootstrap has the TEST_DB_DSN guard'], $reporter->messages('ok'));
    }

    /**
     * A plain unit suite never rebuilds a database, so there is nothing the
     * guard could protect and demanding it would only be noise.
     */
    public function testUnitOnlySuiteNeedsNoGuard(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php require_once __DIR__ . "/../../vendor/autoload.php";',
            'tests/phpunit/PlainTest.php' => '<?php class PlainTest extends \PHPUnit\Framework\TestCase {}',
        ]);
        $reporter = $this->run_(new TestBootstrapGuardCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(
            ['unit-only suite (no Civi\Test) — no test-database guard needed'],
            $reporter->messages('ok'),
        );
    }

    /**
     * The markers live in the test cases, not in bootstrap.php, so the whole
     * tests/ tree is searched — including subdirectories.
     */
    public function testMarkersAreFoundDeeperInTheTestsTree(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php require_once "autoload.php";',
            'tests/phpunit/Civi/Api4/ThingTest.php' => '<?php use Civi\Test\HeadlessInterface as X;',
        ]);
        $this->assertFails($this->run_(new TestBootstrapGuardCheck(), $context), 'TEST_DB_DSN guard');
    }

    public function testTransactionalInterfaceAlsoCountsAsCiviTest(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php require_once "autoload.php";',
            'tests/phpunit/TxTest.php' => '<?php class TxTest implements TransactionalInterface {}',
        ]);
        $this->assertFails($this->run_(new TestBootstrapGuardCheck(), $context));
    }

    /**
     * The guard has to run, not be described. Two repos passed this check while
     * mentioning TEST_DB_DSN only in explanatory comments — and this is what
     * stands between a headless run and the main dev database.
     */
    public function testAMentionInACommentIsNotAGuard(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'tests/phpunit/bootstrap.php' => "<?php\n// The suite boots against TEST_DB_DSN from ~/.cv.json.\n/** TEST_DB_DSN again */\n",
            'tests/phpunit/SomeHeadlessTest.php' => '<?php class T implements \\Civi\\Test\\HeadlessInterface {}',
        ]);
        $this->assertFails($this->run_(new TestBootstrapGuardCheck(), $context), 'lacks the TEST_DB_DSN guard');
    }

    public function testTheGuardInExecutableCodeCounts(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'tests/phpunit/bootstrap.php' => "<?php\n// Explained here.\nif (!str_contains(\$raw, 'TEST_DB_DSN')) { exit(1); }\n",
            'tests/phpunit/SomeHeadlessTest.php' => '<?php class T implements \\Civi\\Test\\HeadlessInterface {}',
        ]);
        $this->assertPasses($this->run_(new TestBootstrapGuardCheck(), $context));
    }
}
