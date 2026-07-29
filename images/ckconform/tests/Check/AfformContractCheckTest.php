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
}
