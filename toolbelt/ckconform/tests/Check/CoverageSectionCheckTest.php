<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CoverageSectionCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CoverageSectionCheckTest extends CheckTestCase
{
    public function testFailsWithoutACoverageSection(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><testsuites/></phpunit>',
        ]);
        $this->assertFails(
            $this->run_(new CoverageSectionCheck(), $context),
            'phpunit config has no <coverage> section — coverage runs measure nothing',
        );
    }

    public function testPassesWithACoverageSection(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><coverage><include><directory>Civi</directory></include></coverage></phpunit>',
        ]);
        $this->assertPasses($this->run_(new CoverageSectionCheck(), $context));
    }

    public function testPlainPhpunitXmlAlsoCounts(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml' => '<?xml version="1.0"?><phpunit><coverage/></phpunit>',
        ]);
        $this->assertPasses($this->run_(new CoverageSectionCheck(), $context));
    }

    /**
     * The tightening over bash: `grep '<coverage'` matched the commented-out
     * section too, so a repo that had switched coverage off still reported that
     * it declared sources.
     */
    public function testACommentedOutSectionIsNotEnough(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><!-- <coverage><include/></coverage> --><testsuites/></phpunit>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context));
    }

    public function testSilentWithoutATestDirectory(): void
    {
        $this->assertSilent($this->run_(new CoverageSectionCheck(), $this->repo([])));
    }
}
