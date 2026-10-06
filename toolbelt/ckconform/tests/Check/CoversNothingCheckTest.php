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

    /** The real-world 0% case: the template annotation left in a headless test. */
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

    public function testACommentMentioningTheAnnotationIsSilent(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php'
                => "<?php\n// civix adds @coversNothing here; removed so coverage counts.\nclass PullTest {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function attributes(): iterable
    {
        yield 'imported' => ["use PHPUnit\\Framework\\Attributes\\CoversNothing;\n#[CoversNothing]\n"];
        yield 'fully qualified' => ["#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\n"];
        yield 'in a group' => ["#[Group('x'), CoversNothing]\n"];
    }

    /** @dataProvider attributes */
    public function testTheAttributeWarns(string $attribute): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php' => "<?php\n{$attribute}class PullTest {}\n",
        ], git: true);
        $this->assertWarns($this->run_(new CoversNothingCheck(), $context), 'PullTest.php');
    }

    /** Near miss: an attribute that merely takes a similar name as data. */
    public function testOtherAttributesAndAnnotationsAreSilent(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php' => "<?php\n/** @coversNothingElse */\n"
                . "#[CoversClass(Pull::class)]\nclass PullTest { const X = 'CoversNothing'; }\n",
        ], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    private const DELIBERATE = "<?php\nclass PullTest {\n  // ckconform-ignore %s -- measures the bootstrap only\n"
        . "  /** @coversNothing */\n  public function testBoots() {}\n}\n";

    /** The README promises inline ignores for every finding tied to a line. */
    public function testAnInlineIgnoreSilencesADeliberateAnnotation(): void
    {
        $context = $this->repo(['tests/phpunit/PullTest.php' => sprintf(self::DELIBERATE, 'covers-nothing')], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    public function testAnIgnoreForAnotherCheckSilencesNothing(): void
    {
        $context = $this->repo(['tests/phpunit/PullTest.php' => sprintf(self::DELIBERATE, 'raw-sql')], git: true);
        $this->assertWarns($this->run_(new CoversNothingCheck(), $context), 'PullTest.php:4');
    }

    public function testEachAnnotationIsReportedAtItsLine(): void
    {
        $context = $this->repo([
            'tests/phpunit/PullTest.php' => "<?php\n/** @coversNothing */\n#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\nclass PullTest {}\n",
        ], git: true);
        $reporter = $this->run_(new CoversNothingCheck(), $context);
        $this->assertWarns($reporter, 'PullTest.php:2');
        $this->assertWarns($reporter, 'PullTest.php:3');
    }

    public function testCoversNothingAsAnAttributeArgumentDoesNotCount(): void
    {
        $context = $this->repo([
            'tests/phpunit/PullTest.php' => "<?php\n#[Foo([CoversNothing::class]), Bar(CoversNothing)]\nclass PullTest {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CoversNothingCheck(), $context));
    }

    public function testCoversNothingAfterAnotherAttributeInTheGroupCounts(): void
    {
        $context = $this->repo([
            'tests/phpunit/PullTest.php' => "<?php\n#[Group('x'), CoversNothing]\nclass PullTest {}\n",
        ], git: true);
        $this->assertWarns($this->run_(new CoversNothingCheck(), $context), 'PullTest.php:2');
    }
}
