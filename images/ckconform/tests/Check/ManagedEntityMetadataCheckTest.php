<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ManagedEntityMetadataCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ManagedEntityMetadataCheckTest extends CheckTestCase
{
    public function testSilentWithoutManagedFiles(): void
    {
        $context = $this->repo(['CRM/Foo.php' => '<?php']);
        $this->assertSilent($this->run_(new ManagedEntityMetadataCheck(), $context));
    }

    public function testPassesOnCleanManagedFile(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            use CRM_Fixture_ExtensionUtil as E;
            return [
              [
                'name' => 'Cron:Fixture.sync',
                'entity' => 'Job',
                'cleanup' => 'never',
                'update' => 'never',
                'params' => [
                  'version' => 4,
                  'values' => [
                    'name' => E::ts('Fixture sync'),
                    'api_entity' => 'Fixture',
                    'api_action' => 'sync',
                  ],
                ],
              ],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedEntityMetadataCheck(), $context));
    }

    public function testFailsOnMissingRequiredKeys(): void
    {
        $context = $this->repo(['managed/Broken.mgd.php' => <<<'PHP'
            <?php
            return [
              ['entity' => 'OptionValue', 'cleanup' => 'never', 'params' => ['version' => 4, 'values' => []]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "missing required key 'name'",
        );
    }

    public function testFailsOnMissingVersion(): void
    {
        $context = $this->repo(['managed/NoVersion.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'Contact', 'params' => ['values' => []]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "no 'version'",
        );
    }

    public function testFailsOnBogusVersion(): void
    {
        $context = $this->repo(['managed/Version.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'Contact', 'params' => ['version' => 5, 'values' => []]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "version '5' is not 3 or 4",
        );
    }

    public function testFailsOnV4WithoutValues(): void
    {
        $context = $this->repo(['managed/NoValues.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'Contact', 'params' => ['version' => 4]],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "without 'values'",
        );
    }

    public function testV3ParamsNeedNoValues(): void
    {
        $context = $this->repo(['managed/V3.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'a',
                'entity' => 'Contact',
                'cleanup' => 'never',
                'params' => ['version' => 3, 'contact_type' => 'Organization'],
              ],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new ManagedEntityMetadataCheck(), $context);
        // Structurally fine — v3 params carry the values inline — but the
        // record still gets the prefer-v4 format advice.
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'uses APIv3');
    }

    public function testFailsOnBogusCleanupAndUpdate(): void
    {
        $context = $this->repo(['managed/Flags.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'a',
                'entity' => 'Contact',
                'cleanup' => 'sometimes',
                'update' => 'maybe',
                'params' => ['version' => 4, 'values' => []],
              ],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new ManagedEntityMetadataCheck(), $context);
        $this->assertFails($reporter, "cleanup 'sometimes'");
        $this->assertFails($reporter, "update 'maybe'");
    }

    public function testFailsOnForeignModule(): void
    {
        $context = $this->repo(['managed/Module.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'a',
                'entity' => 'Contact',
                'module' => 'de.other.extension',
                'cleanup' => 'never',
                'params' => ['version' => 4, 'values' => []],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "is not this extension's key 'fixture'",
        );
    }

    public function testMissingModuleIsFine(): void
    {
        $context = $this->repo(['managed/Module.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'Contact', 'cleanup' => 'never', 'params' => ['version' => 4, 'values' => []]],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedEntityMetadataCheck(), $context));
    }

    public function testFailsOnDuplicateIdentityAcrossFiles(): void
    {
        $record = <<<'PHP'
            <?php
            return [
              ['name' => 'dupe', 'entity' => 'Contact', 'cleanup' => 'never', 'params' => ['version' => 4, 'values' => []]],
            ];
            PHP;
        $context = $this->repo([
            'managed/A.mgd.php' => $record,
            'managed/B.mgd.php' => $record,
        ]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            'duplicate managed identity',
        );
    }

    public function testWarnsOnAdminEditableEntityWithoutCleanup(): void
    {
        $context = $this->repo(['managed/Template.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'welcome',
                'entity' => 'MessageTemplate',
                'params' => ['version' => 4, 'values' => ['msg_title' => 'Welcome']],
              ],
            ];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            "no 'cleanup' is set",
        );
    }

    public function testWarnsWhenFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['managed/Bad.mgd.php' => <<<'PHP'
            <?php
            return [['name' => \Civi\Nope::name()]];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            'could not evaluate',
        );
    }

    public function testFailsWhenFileDoesNotReturnAList(): void
    {
        $context = $this->repo(['managed/NotAList.mgd.php' => "<?php\nreturn 'nope';\n"]);
        $this->assertFails(
            $this->run_(new ManagedEntityMetadataCheck(), $context),
            'does not return a list of managed records',
        );
    }

    public function testWarnsOnAnApiV3Record(): void
    {
        // v3 records still work; v4 with 'values' is the target format.
        $context = $this->repo(['managed/Legacy.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 'a', 'entity' => 'OptionGroup', 'cleanup' => 'unused', 'params' => ['version' => 3, 'name' => 'x', 'title' => 'X']],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new ManagedEntityMetadataCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'uses APIv3');
    }

    public function testAFileIgnoreSilencesTheApiV3Warning(): void
    {
        // require() strips comments before records exist, so the escape is the
        // file-wide ignore, not a line-scoped one.
        $context = $this->repo(['managed/Legacy.mgd.php' => <<<'PHP'
            <?php
            // ckconform-ignore-file managed-entity-metadata -- entity has no APIv4 create on our floor
            return [
              ['name' => 'a', 'entity' => 'OptionGroup', 'cleanup' => 'unused', 'params' => ['version' => 3, 'name' => 'x', 'title' => 'X']],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedEntityMetadataCheck(), $context));
    }
}
