<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4LiteralEntityCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class Api4LiteralEntityCheckTest extends CheckTestCase
{
    /**
     * @param array<string, string> $extra
     */
    private function widget(array $extra): \CiviKitchen\Ckconform\Context
    {
        return $this->repo([
            'Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Api4/WidgetState.php' => "<?php\n",
        ] + $extra, git: true);
    }

    public function testSilentWithoutLocalEntities(): void
    {
        $context = $this->repo([
            'Civi/Ext/Thing.php' => "<?php\ncivicrm_api4('WidgetGhost', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A call to an own entity that was never defined — a typo or rename miss. */
    public function testAnOwnEntityThatDoesNotExistFails(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'WidgetStatee');
    }

    public function testOwnEntitiesThatExistPass(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('Widget', 'get', []);\ncivicrm_api4('WidgetState', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /**
     * The whole point: another extension's entity shares no leading word with
     * ours, so calling civirules from widget must not be flagged.
     */
    public function testAnotherExtensionsEntityIsLeftAlone(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('CiviRulesRule', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** Core entities share no leading word with ours either. */
    public function testCoreEntitiesAreLeftAlone(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('Contact', 'get', []);\ncivicrm_api4('MailingJob', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A civicrm_api4 example in a docblock is not a call. */
    public function testAnExampleInACommentIsNotACall(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\n/** e.g. civicrm_api4('WidgetGhost', 'get') */\nclass Runner {}\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A fixture entity under tests/ is not shipped and must not seed the family. */
    public function testAFixtureEntityDoesNotSeedTheFamily(): void
    {
        $context = $this->repo([
            'tests/fixtures/Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Ext/Runner.php' => "<?php\ncivicrm_api4('WidgetGhost', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A shipped call resolved only by a tests/fixtures entity fatals on an install. */
    public function testAnEntityDefinedOnlyAsAFixtureFails(): void
    {
        $context = $this->widget([
            'tests/fixtures/Civi/Api4/WidgetFixture.php' => "<?php\n",
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetFixture', 'get', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'WidgetFixture');
    }

    /** A dangling call inside tests/ fails that test run itself, not the site. */
    public function testACallInTestsIsNotScanned(): void
    {
        $context = $this->widget([
            'tests/phpunit/RunnerTest.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /**
     * A local CiviRulesRule-style entity puts 'Civi' in the family — which
     * would flag every core CiviCase/CiviMail call. That family is skipped.
     */
    public function testALocalCiviPrefixedEntityDoesNotFlagCoreCiviCalls(): void
    {
        $context = $this->repo([
            'Civi/Api4/CiviRulesRule.php' => "<?php\n",
            'Civi/Ext/Runner.php' => "<?php\ncivicrm_api4('CiviCase', 'get', []);\n",
        ], git: true);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }
}
