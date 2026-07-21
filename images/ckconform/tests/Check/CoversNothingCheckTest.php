<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CoversNothingCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CoversNothingCheckTest extends CheckTestCase
{
    public function testSilentWithoutTests(): void
    {
        $context = $this->repo(['Civi/Ext/Thing.php' => "<?php\nclass Thing {}\n"], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    /** The shuttle 0% case: the template annotation left in a headless test. */
    public function testCoversNothingInATestWarns(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php'
                => "<?php\n/**\n * @coversNothing\n */\nclass PullTest {}\n",
        ], git: true);
        $reporter = $this->run_(new CoversNothingCheck(), $context);
        $this->assertWarns($reporter, 'PullTest.php');
        self::assertSame(0, $reporter->failures());
    }

    public function testATestWithoutTheAnnotationPasses(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php' => "<?php\nclass PullTest {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    /** Only test files: the annotation elsewhere is not this rule's concern. */
    public function testTheAnnotationOutsideTestsIsIgnored(): void
    {
        $context = $this->repo([
            'Civi/Ext/Doc.php' => "<?php\n// documents @coversNothing for readers\nclass Doc {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }
}
