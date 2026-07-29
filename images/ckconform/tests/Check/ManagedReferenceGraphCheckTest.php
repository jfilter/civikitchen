<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ManagedReferenceGraphCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ManagedReferenceGraphCheckTest extends CheckTestCase
{
    public function testSilentWithoutManagedOrAfformFiles(): void
    {
        $context = $this->repo(['CRM/Foo.php' => '<?php']);
        $this->assertSilent($this->run_(new ManagedReferenceGraphCheck(), $context));
    }

    public function testPassesOnResolvableGraph(): void
    {
        $context = $this->repo([
            'managed/SavedSearch_fixtureDonors.mgd.php' => $this->searchAndDisplay(),
            'ang/fixtureDashboard.aff.html' => <<<'HTML'
                <div af-fieldset="">
                  <crm-search-display search-name="fixture_Donors" display-name="fixture_Donors_Table"></crm-search-display>
                </div>
                HTML,
        ]);
        $this->assertSilent($this->run_(new ManagedReferenceGraphCheck(), $context));
    }

    public function testFailsOnDanglingOwnSavedSearchReference(): void
    {
        $context = $this->repo(['managed/Display.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'SearchDisplay_fixture_Donors_Table',
                'entity' => 'SearchDisplay',
                'params' => ['version' => 4, 'values' => [
                  'name' => 'fixture_Donors_Table',
                  'saved_search_id.name' => 'fixture_Donors_Renamed',
                ]],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedReferenceGraphCheck(), $context),
            "references SavedSearch 'fixture_Donors_Renamed'",
        );
    }

    public function testWarnsOnForeignSavedSearchReference(): void
    {
        $context = $this->repo(['managed/Display.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'SearchDisplay_Other_Table',
                'entity' => 'SearchDisplay',
                'params' => ['version' => 4, 'values' => [
                  'name' => 'Other_Table',
                  'saved_search_id' => 'otherext_Contacts',
                ]],
              ],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new ManagedReferenceGraphCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, '<requires> covers it');
    }

    public function testFailsOnDanglingAfformDisplayName(): void
    {
        $context = $this->repo([
            'managed/SavedSearch_fixtureDonors.mgd.php' => $this->searchAndDisplay(),
            'ang/fixtureDashboard.aff.html' => <<<'HTML'
                <crm-search-display search-name="fixture_Donors" display-name="fixture_Donors_Chart"></crm-search-display>
                HTML,
        ]);
        $this->assertFails(
            $this->run_(new ManagedReferenceGraphCheck(), $context),
            'display-name="fixture_Donors_Chart" has no SearchDisplay',
        );
    }

    public function testFailsOnDanglingAfformSearchName(): void
    {
        $context = $this->repo([
            'ang/fixtureDashboard.aff.html' => <<<'HTML'
                <crm-search-display search-name="fixture_Gone" display-name="fixture_Gone_Table"></crm-search-display>
                HTML,
        ]);
        $this->assertFails(
            $this->run_(new ManagedReferenceGraphCheck(), $context),
            'search-name="fixture_Gone" has no SavedSearch',
        );
    }

    public function testWarnsOnForeignAfformSearchName(): void
    {
        $context = $this->repo([
            'ang/fixtureDashboard.aff.html' => <<<'HTML'
                <crm-search-display search-name="otherext_Contacts" display-name="otherext_Contacts_Table"></crm-search-display>
                HTML,
        ]);
        $reporter = $this->run_(new ManagedReferenceGraphCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'target not shipped here');
    }

    public function testWarnsWhenFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['managed/Bad.mgd.php' => "<?php\nreturn [['name' => \\Civi\\Nope::name()]];\n"]);
        $this->assertWarns(
            $this->run_(new ManagedReferenceGraphCheck(), $context),
            'could not evaluate',
        );
    }

    private function searchAndDisplay(): string
    {
        return <<<'PHP'
            <?php
            return [
              [
                'name' => 'SavedSearch_fixture_Donors',
                'entity' => 'SavedSearch',
                'params' => ['version' => 4, 'values' => ['name' => 'fixture_Donors']],
              ],
              [
                'name' => 'SavedSearch_fixture_Donors_SearchDisplay_Table',
                'entity' => 'SearchDisplay',
                'params' => ['version' => 4, 'values' => [
                  'name' => 'fixture_Donors_Table',
                  'saved_search_id.name' => 'fixture_Donors',
                ]],
              ],
            ];
            PHP;
    }
}
