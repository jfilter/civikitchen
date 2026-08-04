<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PhpunitConfigCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PhpunitConfigCheckTest extends CheckTestCase
{
    public function testFailsWhenASuiteHasNoConfig(): void
    {
        $context = $this->repo(['tests/phpunit/SomeTest.php' => '<?php']);
        $this->assertFails($this->run_(new PhpunitConfigCheck(), $context), 'tests/phpunit exists but no phpunit.xml.dist');
    }

    public function testSilentWhenTheConfigIsThere(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit/>',
        ]);
        $this->assertSilent($this->run_(new PhpunitConfigCheck(), $context));
    }

    /** No suite, no opinion — the missing-suite case is a different check. */
    public function testSilentWithoutATestDirectory(): void
    {
        $this->assertSilent($this->run_(new PhpunitConfigCheck(), $this->repo([])));
    }
}
