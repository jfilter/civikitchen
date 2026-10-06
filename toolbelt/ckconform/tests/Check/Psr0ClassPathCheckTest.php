<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Psr0ClassPathCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class Psr0ClassPathCheckTest extends CheckTestCase
{
    public function testSilentWithoutCrmClasses(): void
    {
        $context = $this->repo(['Civi/Ext/Thing.php' => "<?php\nclass Thing {}\n"], git: true);
        $this->assertSilent($this->run_(new Psr0ClassPathCheck(), $context));
    }

    public function testAClassAtItsPathPasses(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Form/Foo.php' => "<?php\nclass CRM_Greeter_Form_Foo {}\n",
        ], git: true);
        $this->assertPasses($this->run_(new Psr0ClassPathCheck(), $context));
    }

    /** The macOS-green/Linux-red case: right path, wrong case. */
    public function testACaseDriftFails(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Form/foo.php' => "<?php\nclass CRM_Greeter_Form_Foo {}\n",
        ], git: true);
        $this->assertFails($this->run_(new Psr0ClassPathCheck(), $context), 'CRM/Greeter/Form/Foo.php');
    }

    /** A directory that does not match the class name (Forms vs Form). */
    public function testAWrongDirectoryFails(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Forms/Foo.php' => "<?php\nclass CRM_Greeter_Form_Foo {}\n",
        ], git: true);
        $this->assertFails($this->run_(new Psr0ClassPathCheck(), $context), 'PSR-0 wants');
    }

    public function testDaoClassesFollowTheSameRule(): void
    {
        $context = $this->repo([
            'CRM/Greeter/DAO/Thing.php' => "<?php\nclass CRM_Greeter_DAO_Thing {}\n",
        ], git: true);
        $this->assertPasses($this->run_(new Psr0ClassPathCheck(), $context));
    }

    /** Files under CRM/ that declare no CRM_ class are not this rule's concern. */
    public function testANonCrmClassUnderCrmIsIgnored(): void
    {
        $context = $this->repo([
            'CRM/Greeter/helpers.php' => "<?php\nfunction greeter_help() {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new Psr0ClassPathCheck(), $context));
    }

    public function testAClassNamedInADocblockIsNotADeclaration(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Page/Main.php' => <<<'PHP'
                <?php
                /**
                 * A listing page like the class CRM_Core_Page_Basic.
                 */
                class CRM_Greeter_Page_Main {
                  public function name() { return CRM_Greeter_Page_Main::class; }
                }
                PHP,
        ], git: true);
        $this->assertPasses($this->run_(new Psr0ClassPathCheck(), $context));
    }

    /** The autoloader looks for each class at its own path, not just the first. */
    /** It loads only after its neighbour, which is a hazard rather than a certain failure. */
    public function testASecondClassInAShippedFileWarns(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Page/Main.php' => "<?php\nclass CRM_Greeter_Page_Helper {}\nclass CRM_Greeter_Page_Main {}\n",
        ], git: true);
        $reporter = $this->run_(new Psr0ClassPathCheck(), $context);
        $this->assertWarns($reporter, 'CRM_Greeter_Page_Helper beside CRM_Greeter_Page_Main in CRM/Greeter/Page/Main.php');
        self::assertSame(0, $reporter->failures());
    }

    public function testAMisfiledClassBesideAnotherStillFails(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Page/Main.php' => "<?php\nclass CRM_Greeter_Page_Mian {}\nclass CRM_Greeter_Page_Helper {}\n",
        ], git: true);
        $this->assertFails($this->run_(new Psr0ClassPathCheck(), $context), 'CRM_Greeter_Page_Mian is in CRM/Greeter/Page/Main.php');
    }

    /** Core's tests/phpunit/CRM/Dedupe/BAO/RuleGroupTest.php declares fixture classes this way. */
    public function testAFixtureClassBesideATestIsFine(): void
    {
        $context = $this->repo([
            'tests/phpunit/CRM/Fixture/FooTest.php' => "<?php\nclass CRM_Fixture_FooTest {}\nclass CRM_Fixture_DAO_TestEntity {}\n",
        ], git: true);
        $this->assertOk($this->run_(new Psr0ClassPathCheck(), $context));
    }

    public function testAGuardedPolyfillIsNotADeclarationOfThisFile(): void
    {
        $context = $this->repo([
            'CRM/Greeter/Compat.php' => "<?php\nclass CRM_Greeter_Compat {}\nif (!class_exists('CRM_Core_Foo')) {\n  class CRM_Core_Foo {}\n}\n",
        ], git: true);
        $this->assertOk($this->run_(new Psr0ClassPathCheck(), $context));
    }
}
