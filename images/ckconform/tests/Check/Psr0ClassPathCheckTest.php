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
            'CRM/Herald/Form/Foo.php' => "<?php\nclass CRM_Herald_Form_Foo {}\n",
        ], git: true);
        $this->assertPasses($this->run_(new Psr0ClassPathCheck(), $context));
    }

    /** The macOS-green/Linux-red case: right path, wrong case. */
    public function testACaseDriftFails(): void
    {
        $context = $this->repo([
            'CRM/Herald/Form/foo.php' => "<?php\nclass CRM_Herald_Form_Foo {}\n",
        ], git: true);
        $this->assertFails($this->run_(new Psr0ClassPathCheck(), $context), 'CRM/Herald/Form/Foo.php');
    }

    /** A directory that does not match the class name (Forms vs Form). */
    public function testAWrongDirectoryFails(): void
    {
        $context = $this->repo([
            'CRM/Herald/Forms/Foo.php' => "<?php\nclass CRM_Herald_Form_Foo {}\n",
        ], git: true);
        $this->assertFails($this->run_(new Psr0ClassPathCheck(), $context), 'PSR-0 wants');
    }

    public function testDaoClassesFollowTheSameRule(): void
    {
        $context = $this->repo([
            'CRM/Herald/DAO/Thing.php' => "<?php\nclass CRM_Herald_DAO_Thing {}\n",
        ], git: true);
        $this->assertPasses($this->run_(new Psr0ClassPathCheck(), $context));
    }

    /** Files under CRM/ that declare no CRM_ class are not this rule's concern. */
    public function testANonCrmClassUnderCrmIsIgnored(): void
    {
        $context = $this->repo([
            'CRM/Herald/helpers.php' => "<?php\nfunction herald_help() {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new Psr0ClassPathCheck(), $context));
    }
}
