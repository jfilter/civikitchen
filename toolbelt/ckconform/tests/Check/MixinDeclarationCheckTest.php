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

    public function testTheWarningNamesTheCivixCommandForEveryMissingMixin(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info(''),
            'xml/Menu/ext.xml' => "<menu></menu>\n",
            'managed/Thing.mgd.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertWarns(
            $this->run_(new MixinDeclarationCheck(), $context),
            '`civix mixin --enable=mgd-php@<version>,menu-xml@<version>`',
        );
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

    /** Older civix loaded settings through the hook; that extension is not broken. */
    public function testSettingsLoadedThroughTheHookPass(): void
    {
        $context = $this->repo([
            'info.xml' => str_replace('key="ext"', 'key="org.example.ext"', $this->info('')),
            'ext.php' => "<?php\nfunction ext_civicrm_alterSettingsFolders(&\$folders) {\n  \$folders[] = __DIR__ . '/settings';\n}\n",
            'ext.civix.php' => "<?php\n",
            'settings/ext.setting.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testMenusLoadedThroughTheHookPass(): void
    {
        $context = $this->repo([
            'info.xml' => str_replace('key="ext"', 'key="org.example.ext"', $this->info('')),
            'ext.php' => "<?php\nfunction ext_civicrm_xmlMenu(&\$files) {\n  \$files[] = __DIR__ . '/xml/Menu/ext.xml';\n}\n",
            'ext.civix.php' => "<?php\n",
            'xml/Menu/ext.xml' => "<menu></menu>\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    /** A hook named only in a comment, or under a foreign prefix, loads nothing. */
    public function testSettingsWithoutTheRealHookStillWarn(): void
    {
        $context = $this->repo([
            'info.xml' => str_replace('key="ext"', 'key="org.example.ext"', $this->info('')),
            'ext.php' => "<?php\n// function ext_civicrm_alterSettingsFolders() was removed\n"
                . "function other_civicrm_alterSettingsFolders(&\$folders) {}\n",
            'ext.civix.php' => "<?php\n",
            'settings/ext.setting.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), 'setting-php');
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

    /** Core lists such entities on the status page, so this one fails. */
    public function testAnApi4EntityWithoutScanClassesFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info("    <mixin>mgd-php@2.0.0</mixin>\n"),
            'Civi/Api4/Widget.php' => "<?php\nnamespace Civi\\Api4;\nclass Widget {}\n",
        ], git: true);
        $reporter = $this->run_(new MixinDeclarationCheck(), $context);
        $this->assertFails($reporter, '`civix mixin --enable=scan-classes@<version>`');
        self::assertSame(0, $reporter->warnings());
    }

    /** A missing scan-classes fails on its own; the softer gaps still only warn. */
    public function testScanClassesFailsBesideAWarning(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info(''),
            'Civi/Api4/Widget.php' => "<?php\nnamespace Civi\\Api4;\nclass Widget {}\n",
            'xml/Menu/ext.xml' => "<menu></menu>\n",
        ], git: true);
        $reporter = $this->run_(new MixinDeclarationCheck(), $context);
        self::assertSame(1, $reporter->failures());
        self::assertStringNotContainsString('menu-xml', implode("\n", $reporter->messages('fail')));
        $this->assertWarns($reporter, '`civix mixin --enable=menu-xml@<version>`');
        self::assertSame(1, $reporter->warnings());
        self::assertStringNotContainsString('scan-classes', implode("\n", $reporter->messages('warn')));
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

    /** Test trees ship nothing; their Civi/Api4 paths are fixtures, not entities. */
    public function testArtefactPathsUnderTestsDoNotCount(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Api4/ContactSaveTest.php' => '<?php',
            'tests/fixtures/managed/Thing.mgd.php' => '<?php return [];',
            'tests/fixtures/xml/Menu/myext.xml' => '<menu/>',
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    /** Core loads from fixed paths below the root; a nested example tree is not the extension's. */
    public function testArtefactsOutsideTheMixinPathsDoNotCount(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info(''),
            'solutions/step/4/managed/Thing.mgd.php' => "<?php\nreturn [];\n",
            'solutions/step/3/schema/Note.entityType.php' => "<?php\nreturn [];\n",
            'solutions/step/4/settings/step.setting.php' => "<?php\nreturn [];\n",
            'solutions/step/4/Civi/Api4/Note.php' => "<?php\nclass Note {}\n",
            'solutions/step/4/xml/Menu/step.xml' => "<menu></menu>\n",
            'solutions/step/4/ang/step.ang.php' => "<?php\nreturn [];\n",
            'schema/sub/Note.entityType.php' => "<?php\nreturn [];\n",
            'ang/sub/step.ang.php' => "<?php\nreturn [];\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    /** mgd-php reads the whole managed/ and api/ subtrees and *.mgd.php at the root. */
    public function testManagedRecordsCountInEveryPathCoreLoads(): void
    {
        $paths = ['Thing.mgd.php', 'managed/sub/Thing.mgd.php', 'api/v3/Thing.mgd.php', 'CRM/Ext/Thing.mgd.php', 'Civi/Ext/Thing.mgd.php'];
        foreach ($paths as $path) {
            $context = $this->repo([
                'info.xml' => $this->info(''),
                $path => "<?php\nreturn [];\n",
            ], git: true);
            $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), 'mgd-php');
        }
    }

    /** setting-php hands settings/ to a recursive scan, ang-php reads ang/ directly. */
    public function testNestedSettingsAndRootAngularModulesCount(): void
    {
        foreach (['settings/sub/ext.setting.php' => 'setting-php', 'ang/ext.ang.php' => 'ang-php'] as $path => $mixin) {
            $context = $this->repo(['info.xml' => $this->info(''), $path => "<?php\nreturn [];\n"], git: true);
            $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), $mixin);
        }
    }

    /** class_exists() rejects traits and interfaces, so core never lists them. */
    public function testATraitOrInterfaceInApi4IsNoEntity(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info(''),
            'Civi/Api4/WidgetTrait.php' => "<?php\nnamespace Civi\\Api4;\ntrait WidgetTrait { public function n() { return self::class; } }\n",
            'Civi/Api4/WidgetInterface.php' => "<?php\nnamespace Civi\\Api4;\ninterface WidgetInterface {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    public function testASuppressedEntityFileDoesNotFail(): void
    {
        $context = $this->repo([
            'info.xml' => $this->info(''),
            'Civi/Api4/Widget.php' => "<?php\n// ckconform-ignore-file mixin-declaration -- registered by the scanClasses hook\n"
                . "namespace Civi\\Api4;\nclass Widget {}\n",
        ], git: true);
        $reporter = $this->run_(new MixinDeclarationCheck(), $context);
        self::assertSame(0, $reporter->failures());
        self::assertSame(0, $reporter->warnings());
    }

    /** entity-types-php@1 read xml/schema/CRM/; older civix loaded it through the shim's hook. */
    public function testTheLegacySchemaLayoutCountsUnlessTheHookLoadsIt(): void
    {
        $files = ['xml/schema/CRM/Ext/Thing.entityType.php' => "<?php\nreturn [];\n"];
        $context = $this->repo(['info.xml' => $this->info('')] + $files, git: true);
        $this->assertWarns($this->run_(new MixinDeclarationCheck(), $context), 'entity-types-php');

        $context = $this->repo([
            'info.xml' => str_replace('key="ext"', 'key="org.example.ext"', $this->info('')),
            'ext.php' => "<?php\nfunction ext_civicrm_entityTypes(&\$entityTypes) {}\n",
            'ext.civix.php' => "<?php\n",
        ] + $files, git: true);
        $this->assertSilent($this->run_(new MixinDeclarationCheck(), $context));
    }

    /** The hook proves nothing about which classes it registers; only the mixin counts. */
    public function testAnOwnScanClassesHookStillFails(): void
    {
        $context = $this->repo([
            'info.xml' => str_replace('key="ext"', 'key="org.example.ext"', $this->info('')),
            'ext.php' => "<?php\nfunction ext_civicrm_scanClasses(array &\$classes) {}\n",
            'ext.civix.php' => "<?php\n",
            'Civi/Api4/Widget.php' => "<?php\nnamespace Civi\\Api4;\nclass Widget {}\n",
        ], git: true);
        $this->assertFails($this->run_(new MixinDeclarationCheck(), $context), 'scan-classes');
    }
}
