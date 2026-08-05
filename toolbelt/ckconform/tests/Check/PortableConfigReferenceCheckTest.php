<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PortableConfigReferenceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PortableConfigReferenceCheckTest extends CheckTestCase
{
    public function testSilentWithoutConfig(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new PortableConfigReferenceCheck(), $context));
    }

    public function testPassesOnPortableConfig(): void
    {
        $context = $this->repo([
            'managed/Search.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'SavedSearch_myext',
                    'entity' => 'SavedSearch',
                    'params' => [
                      'version' => 4,
                      'values' => [
                        'api_entity' => 'Contact',
                        'api_params' => [
                          'version' => 4,
                          'select' => ['id', 'my_group.my_field'],
                          'where' => [['is_deleted', '=', 0]],
                        ],
                      ],
                    ],
                  ],
                ];
                PHP,
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"><af-field name="my_group.my_field" /></af-form>',
        ]);
        $this->assertSilent($this->run_(new PortableConfigReferenceCheck(), $context));
    }

    public function testFailsOnNumericCustomFieldReference(): void
    {
        $context = $this->repo(['managed/Search.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'SavedSearch', 'params' => [
                'version' => 4,
                'values' => ['api_params' => ['select' => ['custom_42']]],
              ]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            "'custom_42' names a custom field by numeric ID",
        );
    }

    public function testFailsOnNumericIdKeys(): void
    {
        $context = $this->repo(['managed/Field.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'f', 'entity' => 'CustomField', 'params' => [
                'version' => 4,
                'values' => ['custom_group_id' => 7, 'option_group_id' => '13'],
              ]],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new PortableConfigReferenceCheck(), $context);
        $this->assertFails($reporter, 'custom_group_id = 7');
        self::assertStringContainsString('option_group_id = 13', implode("\n", $reporter->messages('FAIL')));
    }

    public function testDoesNotFlagLegitimateNumbers(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'j', 'entity' => 'Job', 'params' => [
                'version' => 4,
                'values' => ['domain_id' => 1, 'is_active' => 1, 'weight' => 42, 'run_frequency' => 'Hourly'],
              ]],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new PortableConfigReferenceCheck(), $context));
    }

    public function testFailsOnSerializedStringInStructuredField(): void
    {
        $context = $this->repo(['managed/Search.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'SavedSearch', 'params' => [
                'version' => 4,
                'values' => ['api_params' => ['select' => 'a:1:{i:0;s:2:"id";}']],
              ]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            'PHP-serialized string inside a structured field',
        );
    }

    public function testWarnsOnDoubleJsonEncodedValue(): void
    {
        $context = $this->repo(['managed/Display.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'd', 'entity' => 'SearchDisplay', 'params' => [
                'version' => 4,
                'values' => ['settings' => ['columns' => '"[{\"key\":\"id\"}]"']],
              ]],
            ];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            'looks double JSON-encoded',
        );
    }

    public function testWarnsOnNumericCustomFieldInAfformHtml(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"><af-field name="custom_17" /></af-form>',
        ]);
        $this->assertWarns(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            'af-field name="custom_17"',
        );
    }

    public function testFailsOnNumericCustomFieldInAfformJson(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[],"layout":{"select":["custom_9"]}}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"></af-form>',
        ]);
        $this->assertFails(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            "'custom_9' names a custom field by numeric ID",
        );
    }

    public function testWarnsWhenManagedFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['managed/Broken.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'b', 'entity' => 'Job', 'params' => [
                'version' => 4,
                'values' => ['domain_id' => CRM_Core_Config::domainID()],
              ]],
            ];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new PortableConfigReferenceCheck(), $context),
            'could not evaluate',
        );
    }
}
