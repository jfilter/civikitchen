<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Check\ManagedJobCheck;
use CiviKitchen\Ckconform\Check\SettingsMetadataCheck;
use CiviKitchen\Ckconform\ExtensionUtilStub;

final class ExtensionUtilStubTest extends CheckTestCase
{
    public function testTheCivixConstantsComeFromInfoXml(): void
    {
        ExtensionUtilStub::register($this->repo(['info.xml' => $this->infoXml(key: 'org.example.stubconst')]));
        $class = 'CRM_Stubconst_ExtensionUtil';
        self::assertSame(['stubconst', 'org.example.stubconst', 'CRM_Stubconst'], [
            constant($class . '::SHORT_NAME'), constant($class . '::LONG_NAME'), constant($class . '::CLASS_PREFIX'),
        ]);
    }

    /** A managed file using E::LONG_NAME is still judged, not skipped as unevaluable. */
    public function testAManagedFileUsingLongNameIsChecked(): void
    {
        $context = $this->repo([
            'managed/Job_Sync.mgd.php' => "<?php\nuse CRM_Myext_ExtensionUtil as E;\nreturn [['name' => 'Job_Sync', 'entity' => 'Job', 'update' => 'always', 'params' => ['version' => 4, 'values' => ['name' => 'Sync', 'description' => E::ts('Nightly sync for %1', [1 => E::LONG_NAME]), 'run_frequency' => 'Nightly', 'api_entity' => 'Myext', 'api_action' => 'sync']]]];\n",
        ], git: true);
        $reporter = $this->run_(new ManagedJobCheck(), $context);
        self::assertStringNotContainsString('could not evaluate', $reporter->render());
        $this->assertFails($reporter);
    }

    /** settings_pages keyed by E::SHORT_NAME still evaluates, so the missing html_type is found. */
    public function testASettingsFileUsingShortNameIsChecked(): void
    {
        $context = $this->repo([
            'settings/Myext.setting.php' => "<?php\nuse CRM_Myext_ExtensionUtil as E;\nreturn ['myext_flag' => ['name' => 'myext_flag', 'type' => 'Boolean', 'default' => 0, 'title' => E::ts('Flag'), 'settings_pages' => [E::SHORT_NAME => ['weight' => 1]]]];\n",
        ], git: true);
        $reporter = $this->run_(new SettingsMetadataCheck(), $context);
        self::assertStringNotContainsString('unchecked', $reporter->render());
        $this->assertFails($reporter, 'html_type');
    }
}
