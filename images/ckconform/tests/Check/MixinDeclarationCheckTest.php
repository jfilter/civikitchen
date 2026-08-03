<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MixinDeclarationCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MixinDeclarationCheckTest extends CheckTestCase
{
    private function info(string $mixins): string
    {
        return "<?xml version=\"1.0\"?>\n<extension key=\"ext\" type=\"module\">\n"
            . "  <mixins>\n" . $mixins . "  </mixins>\n</extension>\n";
    }

    /** The greeter case: a menu file no declared mixin loads. */
    public function testAMenuFileWithoutMenuXmlWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@1.0.0</mixin>\n"),
            'xml/Menu/ext.xml' => "<menu></menu>\n",
        ], git: true);
        $reporter = $this->run_(new MixinDeclarationCheck(), $context);
        $this->assertWarns($reporter, 'menu-xml');
        self::assertSame(0, $reporter->failures());
    }

    public function testTheMixngBeingDeclaredPasses(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>menu-xml@1.0.0</mixin>\n"),
            'xml/Menu/ext.xml' => "<menu></menu>\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    /** A different mixin version still counts. */
    public function testVersionIsIgnored(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'managed/Thing.mgd.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testAnEntitySchemaWithoutItsMixinWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'schema/Thing.entityType.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), 'entity-types-php');
    }

    public function testNoArtefactsNoWarning(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'Civi/Ext/Thing.php' => "<?php\nclass Thing {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testAnApi4EntityWithoutScanClassesWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'Civi/Api4/Widget.php' => "<?php\nnamespace Civi\\Api4;\nclass Widget {}\n",
        ], git: true);
        $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), 'scan-classes');
    }

    /** Action classes live below Civi/Api4/ and are not scanned entities. */
    public function testActionClassesAloneDoNotDemandScanClasses(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'Civi/Api4/Action/Widget/Refresh.php' => "<?php\nclass Refresh {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testAnIgnoredEntityFileIsNoEvidence(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'Civi/Api4/Widget.php' => "<?php\n// ckconform-ignore-file mixin-declaration -- loaded by a bespoke hook\n"
                . "namespace Civi\\Api4;\nclass Widget {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testEveryMissingMixinIsNamed(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>scan-classes@1.0.0</mixin>\n"),
            'managed/Thing.mgd.php' => "<?php\nreturn [];\n",
            'xml/Menu/ext.xml' => "<menu></menu>\n",
        ], git: true);
        $reporter = $this->run_(new MixinDeclarationCheck(), $context);
        $message = implode("\n", $reporter->messages('warn'));
        self::assertStringContainsString('mgd-php', $message);
        self::assertStringContainsString('menu-xml', $message);
    }
}
