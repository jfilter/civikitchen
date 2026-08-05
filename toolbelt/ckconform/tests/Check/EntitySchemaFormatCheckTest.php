<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\EntitySchemaFormatCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class EntitySchemaFormatCheckTest extends CheckTestCase
{
    public function testWarnsOnXmlSchemaWithAModernFloor(): void
    {
        // Default fixture floor is 6.10, well past 5.75.
        $context = $this->repo([
            'xml/schema/CRM/Fixture/Thing.xml' => "<table/>\n",
        ]);
        $reporter = $this->run_(new EntitySchemaFormatCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'schema/*.entityType.php');
    }

    public function testAnOldFloorKeepsTheLegacyFormatSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '5.69'),
            'xml/schema/CRM/Fixture/Thing.xml' => "<table/>\n",
        ]);
        $this->assertSilent($this->run_(new EntitySchemaFormatCheck(), $context));
    }

    public function testWarnsOnEntityTypesPhp1(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>entity-types-php@1.0.0</mixin></mixins>'),
        ]);
        $reporter = $this->run_(new EntitySchemaFormatCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'entity-types-php@2');
    }

    public function testTheModernFormatIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>entity-types-php@2.0.0</mixin></mixins>'),
            'schema/Thing.entityType.php' => "<?php\nreturn [];\n",
        ]);
        $this->assertSilent($this->run_(new EntitySchemaFormatCheck(), $context));
    }
}
