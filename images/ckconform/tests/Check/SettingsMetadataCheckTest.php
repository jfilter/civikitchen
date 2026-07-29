<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\SettingsMetadataCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class SettingsMetadataCheckTest extends CheckTestCase
{
    public function testSilentWithoutSettingsDirectory(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new SettingsMetadataCheck(), $context));
    }

    public function testPassesOnCleanSettings(): void
    {
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            use CRM_Myext_ExtensionUtil as E;
            return [
              'myext_endpoint' => [
                'name' => 'myext_endpoint',
                'settings_pages' => ['myext' => ['weight' => 10]],
                'type' => 'String',
                'html_type' => 'text',
                'title' => E::ts('Endpoint'),
              ],
              'myext_internal_cursor' => [
                'name' => 'myext_internal_cursor',
                'type' => 'Integer',
                'default' => 0,
                'title' => E::ts('Cursor'),
              ],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new SettingsMetadataCheck(), $context));
    }

    public function testFailsOnPageSettingWithoutFormElementType(): void
    {
        // The shape found live: an Array setting put on a settings page without
        // html_type — the generic form fatals with "unregistered element".
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_group_ids' => [
                'name' => 'myext_group_ids',
                'settings_pages' => ['myext' => ['weight' => 96]],
                'type' => 'Array',
                'default' => [],
                'title' => 'Groups',
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new SettingsMetadataCheck(), $context),
            'no html_type/quick_form_type',
        );
    }

    public function testQuickFormTypeAloneIsEnough(): void
    {
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_flag' => [
                'name' => 'myext_flag',
                'settings_pages' => ['myext' => ['weight' => 10]],
                'type' => 'Boolean',
                'quick_form_type' => 'CheckBox',
                'title' => 'Flag',
              ],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new SettingsMetadataCheck(), $context));
    }

    public function testFailsOnKeyNameMismatch(): void
    {
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_endpoint' => [
                'name' => 'myext_endpoint_typo',
                'type' => 'String',
                'title' => 'Endpoint',
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new SettingsMetadataCheck(), $context),
            "diverging 'name'",
        );
    }

    public function testWarnsWhenFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_endpoint' => [
                'name' => 'myext_endpoint',
                'default' => CRM_Core_Config::singleton()->userFramework,
              ],
            ];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new SettingsMetadataCheck(), $context),
            'could not evaluate',
        );
    }
}
