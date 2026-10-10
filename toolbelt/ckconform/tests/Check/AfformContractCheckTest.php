<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\AfformContractCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class AfformContractCheckTest extends CheckTestCase
{
    public function testSilentWithoutAfforms(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testPassesOnCoherentForm(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" label="Individual 1" />
                  <div af-fieldset="Individual1">
                    <af-field name="first_name" />
                  </div>
                </af-form>
                HTML,
        ]);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testWarnsOnMissingMetadataFile(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"></af-form>',
        ]);
        $this->assertWarns(
            $this->run_(new AfformContractCheck(), $context),
            'no ang/myextForm.aff.json (or .aff.php) beside it',
        );
    }

    public function testFailsOnDuplicateAlias(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <af-entity type="Individual" name="Individual1" />
                  <div af-fieldset="Individual1"><af-field name="first_name" /></div>
                </af-form>
                HTML,
        ]);
        $this->assertFails(
            $this->run_(new AfformContractCheck(), $context),
            "alias 'Individual1' is declared twice",
        );
    }

    public function testFailsOnUndeclaredFieldsetAlias(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <div af-fieldset="Organization1"><af-field name="organization_name" /></div>
                </af-form>
                HTML,
        ]);
        $this->assertFails(
            $this->run_(new AfformContractCheck(), $context),
            'af-fieldset="Organization1" binds to an entity alias that is not declared',
        );
    }

    public function testFailsOnUndeclaredDataEntityAlias(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <div af-fieldset="Individual1" data-entity="Individual2">
                    <af-field name="first_name" />
                  </div>
                </af-form>
                HTML,
        ]);
        $this->assertFails(
            $this->run_(new AfformContractCheck(), $context),
            'data-entity="Individual2"',
        );
    }

    public function testWarnsOnFieldOutsideAnyFieldset(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <af-field name="orphan_field" />
                  <div af-fieldset="Individual1"><af-field name="first_name" /></div>
                </af-form>
                HTML,
        ]);
        $reporter = $this->run_(new AfformContractCheck(), $context);
        $this->assertWarns($reporter, 'name="orphan_field"');
        self::assertStringNotContainsString('first_name', implode("\n", $reporter->messages('warn')));
    }

    public function testFailsOnInvalidMetadataJson(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form",}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"></af-form>',
        ]);
        $this->assertFails($this->run_(new AfformContractCheck(), $context), 'invalid JSON');
    }

    public function testWarnsOnMissingPermissionKey(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","title":"Form"}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"></af-form>',
        ]);
        $this->assertWarns(
            $this->run_(new AfformContractCheck(), $context),
            'check the intended audience explicitly',
        );
    }

    public function testBlockWithoutEntitiesKeepsBindingsUnchecked(): void
    {
        // A block's fieldsets are bound by the including form, so an alias it
        // does not declare itself is normal, not a failure.
        $context = $this->repo([
            'ang/afblockMyext.aff.json' => '{"type":"block","permission":[],"entity":"Contact"}',
            'ang/afblockMyext.aff.html' => '<div af-fieldset="Individual1"><af-field name="first_name" /></div>',
        ]);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testFormWithoutEntitiesStillFailsOnBinding(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":[]}',
            'ang/myextForm.aff.html' => '<af-form ctrl="afform"><div af-fieldset="Individual1"><af-field name="first_name" /></div></af-form>',
        ]);
        $this->assertFails(
            $this->run_(new AfformContractCheck(), $context),
            'declared: none',
        );
    }

    /** A block takes its entity from the form that embeds it, so it holds no fieldset (core's afblockNameIndividual). */
    public function testABlockLaysOutFieldsWithoutAFieldset(): void
    {
        $context = $this->repo([
            'ang/afblockMyextName.aff.json' => '{"type":"block","entity_type":"Individual","permission":["access CiviCRM"]}',
            'ang/afblockMyextName.aff.html' => "<div class=\"af-container af-layout-inline\">\n  <af-field name=\"first_name\" />\n  <af-field name=\"last_name\" />\n</div>\n",
        ], git: true);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testAJoinBlockWithPhpMetadataLaysOutFieldsWithoutAFieldset(): void
    {
        $context = $this->repo([
            'ang/afjoinMyextEmail.aff.php' => "<?php\nreturn ['type' => 'block', 'entity_type' => 'Email', 'join_entity' => 'Email', 'permission' => 'access CiviCRM'];\n",
            'ang/afjoinMyextEmail.aff.html' => "<div class=\"af-container\">\n  <af-field name=\"email\" />\n  <af-field name=\"location_type_id\" />\n</div>\n",
        ], git: true);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testAnExtraFieldOutsideEveryFieldsetBindsToTheForm(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.15'),
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <div af-fieldset="Individual1">
                    <af-field name="first_name" />
                  </div>
                  <af-field defn="{name: 'remark', label: 'Remark', input_type: 'Text', data_type: 'String'}" />
                </af-form>
                HTML,
        ]);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testExtraFieldsOnAnOlderFloorWarnOnceAboutTheFloor(): void
    {
        // The fixture floor is 6.10.
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <div af-fieldset="Individual1">
                    <af-field name="first_name" />
                  </div>
                  <af-field defn="{name: 'remark', input_type: 'Text'}" />
                  <af-field defn="{name: 'source', input_type: 'Text'}" />
                </af-form>
                HTML,
        ]);
        $warnings = $this->run_(new AfformContractCheck(), $context)->messages('warn');
        self::assertCount(1, $warnings, implode("\n", $warnings));
        self::assertStringContainsString("supports from 6.15 on — info.xml's compatibility floor is 6.10", $warnings[0]);
    }

    public function testAnExtraFieldInsideAFieldsetNeedsTheFloorToo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.14'),
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <div af-fieldset="Individual1">
                    <af-field defn="{name: 'remark', input_type: 'Text'}" />
                  </div>
                </af-form>
                HTML,
        ]);
        $this->assertWarns($this->run_(new AfformContractCheck(), $context), "compatibility floor is 6.14");
    }

    public function testAnUnquotedNameOutsideEveryFieldsetStillWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.15'),
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => "<af-form ctrl=\"afform\">\n  <af-entity type=\"Contact\" name=\"Individual1\" />\n  <af-field name=first_name defn=\"{label: 'First'}\" />\n</af-form>\n",
        ]);
        $this->assertWarns($this->run_(new AfformContractCheck(), $context), '<af-field name="first_name"> sits outside every af-fieldset');
    }

    public function testANamelessFieldWithoutAnInputTypeOutsideEveryFieldsetStillWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.15'),
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contact" name="Individual1" />
                  <af-field />
                  <af-field name="" />
                  <af-field defn="" />
                  <af-field defn="{label: 'Remark'}" />
                </af-form>
                HTML,
        ]);
        $warnings = $this->run_(new AfformContractCheck(), $context)->messages('warn');
        self::assertCount(4, $warnings, implode("\n", $warnings));
        self::assertStringContainsString('<af-field name="?"> sits outside every af-fieldset', $warnings[3]);
    }

    public function testExtraFieldsOfLaterInputTypesNeed616(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.15'),
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-field defn="{input_type: 'Text', name: 'remark'}" />
                  <af-field defn="{&quot;input_type&quot;: &quot;Date&quot;, &quot;name&quot;: &quot;due&quot;}" />
                  <af-field defn="{input_type: 'CheckBox', name: 'agree'}" />
                </af-form>
                HTML,
        ]);
        $warnings = $this->run_(new AfformContractCheck(), $context)->messages('warn');
        self::assertCount(1, $warnings, implode("\n", $warnings));
        self::assertStringContainsString("input type Date, which CiviCRM supports from 6.16 on — info.xml's compatibility floor is 6.15", $warnings[0]);
    }

    public function testHealthyFieldsWithAnAngleBracketInAnAttributeStaySilentOnAnOldFloor(): void
    {
        // The fixture floor is 6.10, below every extra field.
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => <<<'HTML'
                <af-form ctrl="afform">
                  <af-entity type="Contribution" name="Contribution1" />
                  <div ng-if="count > 1" af-fieldset="Contribution1">
                    <af-field name="total_amount" defn="{label: 'Amount', input_attrs: {placeholder: '> 0'}}" />
                    <af-field defn="{search_operator: '>='}" name="receive_date" />
                  </div>
                  <af-field-group defn="{input_type: 'Text'}"></af-field-group>
                </af-form>
                HTML,
        ]);
        $this->assertSilent($this->run_(new AfformContractCheck(), $context));
    }

    public function testAFormStillWarnsAboutAFieldOutsideEveryFieldset(): void
    {
        $context = $this->repo([
            'ang/myextForm.aff.json' => '{"type":"form","permission":["access CiviCRM"]}',
            'ang/myextForm.aff.html' => "<af-form ctrl=\"afform\">\n  <af-entity type=\"Contact\" name=\"Individual1\" />\n  <af-field name=\"first_name\" />\n</af-form>\n",
        ], git: true);
        $this->assertWarns($this->run_(new AfformContractCheck(), $context), '<af-field name="first_name"> sits outside every af-fieldset');
    }
}
