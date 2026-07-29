<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\UpgraderIntegrityCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class UpgraderIntegrityCheckTest extends CheckTestCase
{
    public function testSilentOnACleanUpgrader(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1001(): bool
                    {
                        $this->executeSqlFile('sql/upgrade_1001.sql');
                        return true;
                    }
                PHP),
            'sql/upgrade_1001.sql' => "SELECT 1;\n",
            'sql/auto_install.sql' => "SELECT 1;\n",
        ], git: true);

        $this->assertSilent($this->run_(new UpgraderIntegrityCheck(), $context));
    }

    public function testFailsWhenTheDeclaredUpgraderClassIsMissing(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'no file for that class is committed');
    }

    public function testFailsOnARevisionCollision(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1(): bool
                    {
                        return true;
                    }

                    public function upgrade_01(): bool
                    {
                        return true;
                    }
                PHP),
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'both revision 1');
    }

    public function testFailsOnANonPublicUpgradeStep(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    private function upgrade_1002(): bool
                    {
                        return true;
                    }
                PHP),
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'is not public');
    }

    public function testFailsOnAMissingSqlFile(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1003(): bool
                    {
                        $this->executeSqlFile('sql/upgrade_1003.sql');
                        return true;
                    }
                PHP),
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'is not committed');
    }

    public function testWarnsOnOrphanSql(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CRM_Fixture_Upgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1004(): bool
                    {
                        return true;
                    }
                PHP),
            'sql/upgrade_1004.sql' => "SELECT 1;\n",
        ], git: true);

        $reporter = $this->run_(new UpgraderIntegrityCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'sql/upgrade_1004.sql');
    }

    public function testSilentOnTheAutomaticUpgraderWithoutADelegate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CiviMix\Schema\Fixture\AutomaticUpgrader</upgrader>'),
        ], git: true);

        $this->assertSilent($this->run_(new UpgraderIntegrityCheck(), $context));
    }

    public function testScansTheAutomaticUpgraderDelegate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CiviMix\Schema\Fixture\AutomaticUpgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    protected function upgrade_1005(): bool
                    {
                        return true;
                    }
                PHP),
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'upgrade_1005() is not public');
    }

    public function testFailsWhenTheAutomaticUpgraderDelegateHasTheWrongClassName(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '  <upgrader>CiviMix\Schema\Fixture\AutomaticUpgrader</upgrader>'),
            'CRM/Fixture/Upgrader.php' => "<?php\n\nclass CRM_Fixture_Upgrade {\n}\n",
        ], git: true);

        $this->assertFails($this->run_(new UpgraderIntegrityCheck(), $context), 'CRM_Fixture_Upgrader');
    }

    public function testWarnsOnAnUndeclaredUpgrader(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1006(): bool
                    {
                        return true;
                    }
                PHP),
        ], git: true);

        $this->assertWarns($this->run_(new UpgraderIntegrityCheck(), $context), 'no <upgrader>');
    }

    public function testSilentOnAnUndeclaredUpgraderWithACivixUpgradeHook(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Upgrader.php' => $this->upgrader(<<<'PHP'
                    public function upgrade_1007(): bool
                    {
                        return true;
                    }
                PHP),
            'fixture.civix.php' => "<?php\n\nfunction _fixture_civix_civicrm_upgrade(\$op, &\$queue = NULL) {\n}\n",
        ], git: true);

        $this->assertSilent($this->run_(new UpgraderIntegrityCheck(), $context));
    }

    public function testSilentOutsideOfAnUpgraderAndSql(): void
    {
        $context = $this->repo(['CRM/Fixture/Form/Thing.php' => "<?php\n"], git: true);

        $this->assertSilent($this->run_(new UpgraderIntegrityCheck(), $context));
    }

    private function upgrader(string $methods): string
    {
        return "<?php\n\nclass CRM_Fixture_Upgrader extends CRM_Extension_Upgrader_Base\n{\n" . $methods . "\n}\n";
    }
}
