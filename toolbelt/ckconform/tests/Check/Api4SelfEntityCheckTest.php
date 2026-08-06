<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4SelfEntityCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Ckconform\Tests\FakeCoreTrait;

final class Api4SelfEntityCheckTest extends CheckTestCase
{
    use FakeCoreTrait;

    /**
     * @param list<string> $entities
     */
    private function core(array $entities = ['Contact', 'Email', 'MailingJob']): void
    {
        $this->makeCore();
        foreach ($entities as $name) {
            file_put_contents(
                $this->core . '/Civi/Api4/' . $name . '.php',
                "<?php\nnamespace Civi\\Api4;\nclass {$name} {}\n"
            );
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function ext(array $files): Context
    {
        $this->core();

        return $this->repo([
            'Civi/Api4/LedgerTransaction.php' => "<?php\n",
        ] + $files, git: true);
    }

    /** A fake entity under tests/fixtures is not shipped and must not vouch for a call. */
    public function testAFixtureEntityDoesNotCountAsShipped(): void
    {
        $context = $this->ext([
            'tests/fixtures/Civi/Api4/LedgerAdapter.php' => "<?php\n",
            'frontend/src/PipelineEditor.tsx' => "const a = await getEntities('LedgerAdapter', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $context), 'LedgerAdapter');
    }

    public function testSilentWithoutJavaScript(): void
    {
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $this->ext([])));
    }

    /**
     * The case this rule exists for: a React component calling an entity of
     * ours that was never written, through a wrapper no regex can follow.
     */
    public function testAnEntityThatExistsNowhereFails(): void
    {
        $context = $this->ext([
            'frontend/src/pipeline-editor/PipelineEditor.tsx'
                => "const a = await getEntities<AdapterDefinition>('LedgerAdapter', [], ['*']);\n",
        ]);
        $reporter = $this->run_(new Api4SelfEntityCheck(), $context);
        $this->assertFails($reporter, 'LedgerAdapter');
        self::assertStringContainsString(
            'PipelineEditor.tsx',
            implode("\n", $reporter->messages('FAIL'))
        );
    }

    public function testAnEntityWeDefineOurselvesPasses(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "getEntities('LedgerTransaction', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    public function testACoreEntityPasses(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "crmApi4('MailingJob', 'get', {});\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * Single-word strings are ordinary code — labels, keys, enum members. Only
     * the multi-word CamelCase shape of an entity class earns a look.
     */
    public function testSingleWordStringsAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "console.log('Hello');\nsetStatus('Pending');\nt('Save');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * SearchDisplay and ScheduledJob names carry underscores and live in
     * argument positions too. An earlier cut of this rule flagged them across
     * four repos.
     */
    public function testUnderscoredNamesAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "display('Acme_Profiles_Table');\njob('AcmeReminder_process');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A name that is not the first argument is not in entity position. */
    public function testOnlyTheFirstArgumentCounts(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "addWhere('name', '=', 'AcmeContactTab');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * `expect(text).not.toBe('TitleParagraph')` is an assertion, not an API
     * call, and nothing about its shape says so.
     */
    public function testAssertionsInTestFilesAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'tests/js/plain-text.test.js' => "expect(text).not.toBe('TitleParagraph');\n",
            'frontend/src/thing.spec.ts' => "expect(x).toEqual('SomeGhostEntity');\n",
        ]);
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $context));
    }

    public function testBuiltArtefactsAreNotScanned(): void
    {
        $context = $this->ext([
            'dist/pipeline-editor.js' => "getEntities('LedgerAdapter')\n",
            'frontend/node_modules/pkg/index.js' => "getEntities('LedgerGhost')\n",
        ]);
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $context));
    }
}
