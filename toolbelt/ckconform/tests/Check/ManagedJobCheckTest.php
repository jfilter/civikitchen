<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ManagedJobCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ManagedJobCheckTest extends CheckTestCase
{
    private function escape(string $parameters): string
    {
        return str_replace(["\\", "\n", '"', '$'], ['\\\\', '\\n', '\\"', '\\$'], $parameters);
    }

    public function testSilentWithoutManagedFiles(): void
    {
        $context = $this->repo(['CRM/Foo.php' => '<?php']);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testSilentWithoutJobRecords(): void
    {
        $context = $this->repo(['managed/Search.mgd.php' => <<<'PHP'
            <?php
            return [
              ['name' => 's', 'entity' => 'SavedSearch', 'params' => ['version' => 4, 'values' => ['name' => 's']]],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testPassesOnCleanJob(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => [
                      'version' => 4,
                      'values' => [
                        'name' => 'Fixture sync',
                        'api_entity' => 'Fixture',
                        'api_action' => 'sync',
                        'run_frequency' => 'Hourly',
                        'is_active' => TRUE,
                        'parameters' => "version=4\ncheckPermissions=0\nlimit=50",
                      ],
                    ],
                  ],
                ];
                PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testFailsWithoutApiEntityAndAction(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:broken',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 4, 'values' => ['name' => 'Broken']],
              ],
            ];
            PHP,
        ]);
        $reporter = $this->run_(new ManagedJobCheck(), $context);
        $this->assertFails($reporter, 'no api_entity');
        $this->assertFails($reporter, 'no api_action');
    }

    public function testFailsOnBogusRunFrequency(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:freq',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 4, 'values' => [
                  'api_entity' => 'Contact',
                  'api_action' => 'get',
                  'run_frequency' => 'hourly',
                ]],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            "run_frequency 'hourly'",
        );
    }

    public function testFailsOnArrayParameters(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:params',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 4, 'values' => [
                  'api_entity' => 'Contact',
                  'api_action' => 'get',
                  'parameters' => ['limit' => 50],
                ]],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            "'parameters' is array",
        );
    }

    public function testFailsOnV3JobWithoutApiAction(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:v3',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 3, 'api_entity' => 'Contact'],
              ],
            ];
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            'no api_action',
        );
    }

    public function testWarnsOnReactivationRisk(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'is_active' => TRUE,
                      'parameters' => "version=4\ncheckPermissions=0",
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertWarns(
            $this->run_(new ManagedJobCheck(), $context),
            're-enables a job an admin disabled',
        );
    }

    public function testWarnsOnOwnApiEntityNotShipped(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:Fixture.sync',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 4, 'values' => [
                  'api_entity' => 'FixtureSync',
                  'api_action' => 'run',
                ]],
              ],
            ];
            PHP,
        ]);
        $this->assertWarns(
            $this->run_(new ManagedJobCheck(), $context),
            "api_entity 'FixtureSync' looks like this extension's own API",
        );
    }

    public function testForeignApiEntityIsNotFlagged(): void
    {
        $context = $this->repo(['managed/Job.mgd.php' => <<<'PHP'
            <?php
            return [
              [
                'name' => 'Cron:core',
                'entity' => 'Job',
                'update' => 'never',
                'params' => ['version' => 4, 'values' => [
                  'api_entity' => 'Contact',
                  'api_action' => 'get',
                ]],
              ],
            ];
            PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testApiV3FileSatisfiesOwnEntity(): void
    {
        $context = $this->repo([
            'api/v3/FixtureSync/Run.php' => '<?php',
            'api/v3/FixtureSync.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'FixtureSync',
                      'api_action' => 'run',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testPassesOnApiV4OnlyEntityWithJsonVersion(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => '{"version":4,"checkPermissions":false}',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testFailsOnApiV4OnlyEntityWithoutVersion(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => "version = 3\nlimit=50",
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            "api_entity 'Fixture' is APIv4-only but parameters do not set version=4",
        );
    }

    public function testFailsOnApiV4OnlyEntityWithoutParameters(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertFails($this->run_(new ManagedJobCheck(), $context), 'do not set version=4');
    }

    public function testApiV3FileKeepsVersionOutOfScope(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'api/v3/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => 'limit=50',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    public function testWarnsOnApiV4OnlyEntityWithoutCheckPermissions(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => 'version=4',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $reporter = $this->run_(new ManagedJobCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'do not set checkPermissions=0');
    }

    public function testCivixApiV3DirectoryKeepsVersionOutOfScope(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'api/v3/Fixture/Sync.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => 'limit=50',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertSilent($this->run_(new ManagedJobCheck(), $context));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedParameterLines(): array
    {
        return [
            'no separator' => ['limit', 'limit'],
            'two separators' => ["version=4\nfoo=a=b", 'foo=a=b'],
            'empty key' => ["version=4\n=50", '=50'],
            'empty value' => ["version=4\nlimit=", 'limit='],
            'blank line' => ["version=4\n\nlimit=50", ''],
        ];
    }

    /**
     * @dataProvider malformedParameterLines
     */
    public function testFailsOnMalformedParameterLine(string $parameters, string $line): void
    {
        $context = $this->repo([
            'api/v3/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<PHP
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => "{$this->escape($parameters)}",
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            "Cron:Fixture.sync': parameters line '$line' is not a single key=value pair",
        );
    }

    public function testFailsOnParametersThatLookLikeJsonButAreNot(): void
    {
        $context = $this->repo([
            'api/v3/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => '{"version":4,}',
                    ]],
                  ],
                ];
                PHP,
        ]);
        $this->assertFails(
            $this->run_(new ManagedJobCheck(), $context),
            'are not valid JSON',
        );
    }

    public function testWarnsOnCheckPermissionsFalseAsText(): void
    {
        $context = $this->repo([
            'Civi/Api4/Fixture.php' => '<?php',
            'managed/Job.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'Cron:Fixture.sync',
                    'entity' => 'Job',
                    'update' => 'never',
                    'params' => ['version' => 4, 'values' => [
                      'api_entity' => 'Fixture',
                      'api_action' => 'sync',
                      'parameters' => "version=4\ncheckPermissions=FALSE",
                    ]],
                  ],
                ];
                PHP,
        ]);
        $reporter = $this->run_(new ManagedJobCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'which APIv4 coerces to TRUE');
    }

    public function testWarnsWhenFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['managed/Bad.mgd.php' => "<?php\nreturn [['name' => \\Civi\\Nope::name()]];\n"]);
        $this->assertWarns(
            $this->run_(new ManagedJobCheck(), $context),
            'could not evaluate',
        );
    }
}
