<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ManagedJobCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ManagedJobCheckTest extends CheckTestCase
{
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
                        'parameters' => "limit=50\ndry_run=0",
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

    public function testWarnsWhenFileCannotBeEvaluated(): void
    {
        $context = $this->repo(['managed/Bad.mgd.php' => "<?php\nreturn [['name' => \\Civi\\Nope::name()]];\n"]);
        $this->assertWarns(
            $this->run_(new ManagedJobCheck(), $context),
            'could not evaluate',
        );
    }
}
