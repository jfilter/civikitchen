<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4SelfEntityCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class Api4SelfEntityCheckTest extends CheckTestCase
{
    private ?string $core = null;

    protected function tearDown(): void
    {
        if ($this->core !== null && is_dir($this->core)) {
            exec('rm -rf ' . escapeshellarg($this->core));
        }
        $this->core = null;
        parent::tearDown();
    }

    protected function coreDir(): ?string
    {
        return $this->core;
    }

    /**
     * @param list<string> $entities
     */
    private function core(array $entities = ['Contact', 'Email', 'MailingJob']): void
    {
        $this->core = sys_get_temp_dir() . '/ckconform-core-' . bin2hex(random_bytes(6));
        mkdir($this->core . '/Civi/Api4', 0777, true);
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
            'Civi/Api4/InflowTransaction.php' => "<?php\n",
        ] + $files, git: true);
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
                => "const a = await getEntities<AdapterDefinition>('InflowAdapter', [], ['*']);\n",
        ]);
        $reporter = $this->run_(new Api4SelfEntityCheck(), $context);
        $this->assertFails($reporter, 'InflowAdapter');
        self::assertStringContainsString(
            'PipelineEditor.tsx',
            implode("\n", $reporter->messages('FAIL'))
        );
    }

    public function testAnEntityWeDefineOurselvesPasses(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "getEntities('InflowTransaction', []);\n",
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
            'frontend/src/x.ts' => "display('Shuttle_Profiles_Table');\njob('MemberhubReminder_process');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A name that is not the first argument is not in entity position. */
    public function testOnlyTheFirstArgumentCounts(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "addWhere('name', '=', 'MemberhubContactTab');\n",
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
            'dist/pipeline-editor.js' => "getEntities('InflowAdapter')\n",
            'frontend/node_modules/pkg/index.js' => "getEntities('InflowGhost')\n",
        ]);
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $context));
    }
}
