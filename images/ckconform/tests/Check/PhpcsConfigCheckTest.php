<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PhpcsConfigCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PhpcsConfigCheckTest extends CheckTestCase
{
    public function testFailsWithoutAnyPhpcsConfig(): void
    {
        $reporter = $this->run_(new PhpcsConfigCheck(), $this->repo([]));
        $this->assertFails($reporter, 'no phpcs.xml.dist');
    }

    public function testPassesWhenTheStandardIsReferenced(): void
    {
        $context = $this->repo([
            'phpcs.xml.dist' => '<?xml version="1.0"?><ruleset name="p"><rule ref="CiviKitchen"/></ruleset>',
        ]);
        $this->assertPasses($this->run_(new PhpcsConfigCheck(), $context));
    }

    public function testFailsWhenAConfigExistsButPicksAnotherStandard(): void
    {
        $context = $this->repo([
            'phpcs.xml.dist' => '<?xml version="1.0"?><ruleset name="p"><rule ref="PSR12"/></ruleset>',
        ]);
        $this->assertFails($this->run_(new PhpcsConfigCheck(), $context), 'does not <rule ref="CiviKitchen"/>');
    }

    public function testPlainPhpcsXmlAlsoCounts(): void
    {
        $context = $this->repo([
            'phpcs.xml' => '<?xml version="1.0"?><ruleset name="p"><rule ref="CiviKitchen.Legacy.NoLegacyCall"/></ruleset>',
        ]);
        $this->assertPasses($this->run_(new PhpcsConfigCheck(), $context));
    }

    /**
     * The bash version grepped for the literal ref="CiviKitchen", so a mention
     * inside a comment satisfied it while phpcs loaded nothing of the sort.
     */
    public function testACommentMentioningTheStandardIsNotEnough(): void
    {
        $context = $this->repo([
            'phpcs.xml.dist' => '<?xml version="1.0"?><ruleset name="p"><!-- ref="CiviKitchen" once, TODO --><rule ref="PSR12"/></ruleset>',
        ]);
        $this->assertFails($this->run_(new PhpcsConfigCheck(), $context));
    }
}
