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
    private function voyage(array $extra): \CiviKitchen\Ckconform\Context
    {
        return $this->repo([
            'Civi/Api4/Voyage.php' => "<?php\n",
            'Civi/Api4/VoyageState.php' => "<?php\n",
        ] + $extra, git: true);
    }

    public function testSilentWithoutLocalEntities(): void
    {
        $context = $this->repo([
            'Civi/Ext/Thing.php' => "<?php\ncivicrm_api4('VoyageGhost', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A call to an own entity that was never defined — a typo or rename miss. */
    public function testAnOwnEntityThatDoesNotExistFails(): void
    {
        $context = $this->voyage([
            'Civi/Voyage/Runner.php' => "<?php\ncivicrm_api4('VoyageStatee', 'get', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'VoyageStatee');
    }

    public function testOwnEntitiesThatExistPass(): void
    {
        $context = $this->voyage([
            'Civi/Voyage/Runner.php' => "<?php\ncivicrm_api4('Voyage', 'get', []);\ncivicrm_api4('VoyageState', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /**
     * The whole point: another extension's entity shares no leading word with
     * ours, so calling civirules from voyage must not be flagged.
     */
    public function testAnotherExtensionsEntityIsLeftAlone(): void
    {
        $context = $this->voyage([
            'Civi/Voyage/Runner.php' => "<?php\ncivicrm_api4('CiviRulesRule', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** Core entities share no leading word with ours either. */
    public function testCoreEntitiesAreLeftAlone(): void
    {
        $context = $this->voyage([
            'Civi/Voyage/Runner.php' => "<?php\ncivicrm_api4('Contact', 'get', []);\ncivicrm_api4('MailingJob', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A civicrm_api4 example in a docblock is not a call. */
    public function testAnExampleInACommentIsNotACall(): void
    {
        $context = $this->voyage([
            'Civi/Voyage/Runner.php' => "<?php\n/** e.g. civicrm_api4('VoyageGhost', 'get') */\nclass Runner {}\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }
}
