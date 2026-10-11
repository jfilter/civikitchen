<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PhpcsConfigCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PhpcsConfigCheckTest extends CheckTestCase
{
    public function testPassesWithoutAPhpcsConfig(): void
    {
        $this->assertPasses($this->run_(new PhpcsConfigCheck(), $this->repo([])));
    }

    /** A project layer over the retired standard: its rules would silently stop applying. */
    public function testFailsOnALeftoverPhpcsConfig(): void
    {
        foreach (['phpcs.xml.dist', 'phpcs.xml'] as $file) {
            $context = $this->repo([$file => '<?xml version="1.0"?><ruleset name="p"><rule ref="CiviKitchen"/></ruleset>']);
            $this->assertFails($this->run_(new PhpcsConfigCheck(), $context), "{$file} is no longer read");
        }
    }
}
