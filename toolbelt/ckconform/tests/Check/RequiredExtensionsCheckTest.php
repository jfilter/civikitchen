<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\RequiredExtensionsCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class RequiredExtensionsCheckTest extends CheckTestCase
{
    public function testSaysNothingForAnExtensionThatUsesNoneOfThem(): void
    {
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $this->repo([], git: true)));
    }

    public function testFailsWhenManagedShipsSearchKitEntitiesWithoutTheExt(): void
    {
        $context = $this->repo([
            'managed/MySearch.mgd.php' => "<?php\nreturn [['entity' => 'SavedSearch', 'name' => 'x']];\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicrm.search_kit — managed/ ships SavedSearch/SearchDisplay entities',
        );
    }

    public function testSearchDisplayAlsoTriggersIt(): void
    {
        $context = $this->repo([
            'managed/nested/Display.mgd.php' => "<?php\nreturn [['entity' => 'SearchDisplay']];\n",
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicrm.search_kit');
    }

    public function testSilentWhenSearchKitIsDeclared(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>org.civicrm.search_kit</ext>\n  </requires>"),
            'managed/MySearch.mgd.php' => "<?php\nreturn [['entity' => 'SavedSearch']];\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    /**
     * A flat ang/*.aff.html glob missed the ang/afform/ layout most repos use,
     * so a repo full of Afforms passed with no <ext> declared for them.
     */
    public function testFindsAfformsNestedBelowAng(): void
    {
        $context = $this->repo([
            'ang/afform/afGreeting.aff.html' => '<div af-fieldset=""></div>',
            'ang/afform/afGreeting.aff.json' => '{"title": "Greeting"}',
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicrm.afform — ang/ ships Afforms',
        );
    }

    public function testFailsForCiviRulesBaseClasses(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Thing.php' => "<?php\nclass CRM_Fixture_Action_Thing extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicoop.civirules — PHP extends CiviRules base classes',
        );
    }

    public function testCivirulesActionsBaseClassAlsoCounts(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Other.php' => "<?php\nclass X extends CRM_CivirulesActions_Generic_Api {}\n",
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicoop.civirules');
    }

    /**
     * The regression this port exists for: an `<ext>[^<]+</ext>` regex could not
     * see a declaration whose element carries an attribute, so a correctly
     * declared dependency was reported as missing.
     */
    public function testAnExtElementWithAnAttributeIsStillADeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                extra: "  <requires>\n    <ext version=\"3.32\">org.civicoop.civirules</ext>\n  </requires>",
            ),
            'CRM/Fixture/Action/Thing.php' => "<?php\nclass T extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testWhitespaceAroundTheKeyIsTrimmed(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>\n      org.civicrm.afform\n    </ext>\n  </requires>"),
            'ang/afform/x.aff.html' => '<div></div>',
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    /**
     * Substring matching (what bash did) would let a longer, unrelated key
     * satisfy the requirement.
     */
    public function testALongerKeyDoesNotSatisfyTheRequirement(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>org.civicrm.afform_extras</ext>\n  </requires>"),
            'ang/afform/x.aff.html' => '<div></div>',
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicrm.afform —');
    }

    public function testAllThreeCanFireTogetherInBashOrder(): void
    {
        $context = $this->repo([
            'managed/S.mgd.php' => "<?php\nreturn [['entity' => 'SavedSearch']];\n",
            'ang/afform/x.aff.json' => '{}',
            'CRM/T.php' => "<?php\nclass T extends CRM_Civirules_Action {}\n",
        ], git: true);
        $reporter = $this->run_(new RequiredExtensionsCheck(), $context);
        self::assertSame(
            [
                'info.xml does not <requires> org.civicrm.search_kit — managed/ ships SavedSearch/SearchDisplay entities',
                'info.xml does not <requires> org.civicrm.afform — ang/ ships Afforms',
                'info.xml does not <requires> org.civicoop.civirules — PHP extends CiviRules base classes',
            ],
            $reporter->messages('FAIL'),
        );
    }
}
