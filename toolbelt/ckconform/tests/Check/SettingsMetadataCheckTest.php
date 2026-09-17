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

    public function testFailsOnSnakeCasePseudoconstantKeys(): void
    {
        // The shape found live: snake_case key_column/label_column are silently
        // ignored by core, leaving keyColumn/labelColumn NULL.
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_group_id' => [
                'name' => 'myext_group_id',
                'type' => 'Integer',
                'title' => 'Group',
                'pseudoconstant' => [
                  'table' => 'civicrm_group',
                  'key_column' => 'id',
                  'label_column' => 'title',
                ],
              ],
            ];
            PHP,
        ]);
        $result = $this->run_(new SettingsMetadataCheck(), $context);
        $this->assertFails($result, "key 'key_column' is not read by core's settings code — use 'keyColumn'");
        $this->assertFails($result, "key 'label_column' is not read by core's settings code — use 'labelColumn'");
    }

    public function testFailsOnTablePseudoconstantWithoutKeyColumnAndLabelColumn(): void
    {
        // Measured on a real install: no keyColumn/labelColumn also leaves
        // both NULL and throws the same fatal as the snake_case case.
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_group_id' => [
                'name' => 'myext_group_id',
                'type' => 'Integer',
                'title' => 'Group',
                'pseudoconstant' => [
                  'table' => 'civicrm_group',
                ],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new SettingsMetadataCheck(), $context),
            'table\' pseudoconstant without both keyColumn and labelColumn',
        );
    }

    public function testPassesOnCorrectTablePseudoconstant(): void
    {
        $context = $this->repo(['settings/myext.setting.php' => <<<'PHP'
            <?php
            return [
              'myext_group_id' => [
                'name' => 'myext_group_id',
                'type' => 'Integer',
                'title' => 'Group',
                'pseudoconstant' => [
                  'table' => 'civicrm_group',
                  'keyColumn' => 'id',
                  'labelColumn' => 'title',
                ],
              ],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new SettingsMetadataCheck(), $context));
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
