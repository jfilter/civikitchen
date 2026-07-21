<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\TestSuiteRequiredCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class TestSuiteRequiredCheckTest extends CheckTestCase
{
    public function testSaysNothingWhenASuiteExists(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php',
            'Civi/Thing.php' => '<?php class Thing {}',
        ]);
        $this->assertSilent($this->run_(new TestSuiteRequiredCheck(), $context));
    }

    public function testFailsWhenThereIsSourceButNoSuite(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            'CRM/Fixture/Page.php' => '<?php class Page {}',
        ]);
        $this->assertFails(
            $this->run_(new TestSuiteRequiredCheck(), $context),
            "no test suite (tests/phpunit) but 2 PHP source file(s) — add tests, or declare 'tests=optional -- <reason>' in .ckconform",
        );
    }

    /**
     * The count once missed this case entirely: a config-only extension keeps
     * its logic in the root <key>.php, so the repo looked like it had nothing
     * worth covering.
     */
    public function testTheRootPhpFileCounts(): void
    {
        $context = $this->repo([
            'fixture.php' => '<?php function fixture_civicrm_config() {}',
        ]);
        $this->assertFails(
            $this->run_(new TestSuiteRequiredCheck(), $context),
            'but 1 PHP source file(s)',
        );
    }

    public function testGeneratedAndMachineOwnedFilesDoNotCount(): void
    {
        $context = $this->repo([
            'fixture.civix.php' => '<?php // generated',
            'phpstanBootstrap.php' => '<?php',
            'CRM/Fixture/DAO/Thing.php' => '<?php class Thing {}',
            'CRM/Fixture/BAO/Thing.php' => '<?php class Thing {}',
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
        ]);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertFails($reporter, 'but 1 PHP source file(s)');
    }

    public function testPassesWhenThereIsNoPhpSourceAtAll(): void
    {
        $context = $this->repo([
            'ang/fixture.aff.html' => '<div></div>',
        ]);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(['no test suite — no PHP source to cover'], $reporter->messages('ok'));
    }

    public function testTheOptOutIsQuotedBackVerbatim(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            '.ckconform' => "# policy\ntests=optional -- config/mgd-only extension, verified by the checks/ harness\n",
        ]);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(
            ['no test suite — declared optional in .ckconform (optional -- config/mgd-only extension, verified by the checks/ harness)'],
            $reporter->messages('ok'),
        );
    }
}
