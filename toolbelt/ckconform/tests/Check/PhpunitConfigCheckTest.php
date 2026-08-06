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

    /** An untracked config ships to nobody, so it cannot satisfy the check. */
    public function testAnUntrackedConfigDoesNotCount(): void
    {
        $context = $this->repo(['tests/phpunit/SomeTest.php' => '<?php'], git: true);
        file_put_contents($context->root . '/phpunit.xml.dist', '<?xml version="1.0"?><phpunit/>');
        $this->assertFails($this->run_(new PhpunitConfigCheck(), $context), 'no phpunit.xml.dist');
    }

    /** An untracked local tests/phpunit is not a suite the repo ships. */
    public function testAnUntrackedSuiteDirectoryIsIgnored(): void
    {
        $context = $this->repo(['Civi/Thing.php' => '<?php'], git: true);
        mkdir($context->root . '/tests/phpunit', 0777, true);
        file_put_contents($context->root . '/tests/phpunit/SomeTest.php', '<?php');
        $this->assertSilent($this->run_(new PhpunitConfigCheck(), $context));
    }

    /** No suite, no opinion — the missing-suite case is a different check. */
    public function testSilentWithoutATestDirectory(): void
    {
        $this->assertSilent($this->run_(new PhpunitConfigCheck(), $this->repo([])));
    }
}
