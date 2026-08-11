<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\EntityDaoStubCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class EntityDaoStubCheckTest extends CheckTestCase
{
    private const SCHEMA = <<<'PHP'
        <?php
        return [
          'name' => 'AcmeThing',
          'table' => 'civicrm_acme_thing',
          'class' => 'CRM_Acme_DAO_AcmeThing',
          'getFields' => fn () => ['id' => ['sql_type' => 'int unsigned']],
        ];
        PHP;

    private const STUB = <<<'PHP'
        <?php
        class CRM_Acme_DAO_AcmeThing extends CRM_Acme_DAO_Base {
          public static $_tableName = 'civicrm_acme_thing';
        }
        PHP;

    public function testFailsWhenTheDaoStubIsMissing(): void
    {
        $context = $this->repo([
            'schema/AcmeThing.entityType.php' => self::SCHEMA,
        ]);
        $reporter = $this->run_(new EntityDaoStubCheck(), $context);
        $this->assertFails($reporter, 'CRM/Acme/DAO/AcmeThing.php');
    }

    public function testPassesWhenTheStubIsShipped(): void
    {
        $context = $this->repo([
            'schema/AcmeThing.entityType.php' => self::SCHEMA,
            'CRM/Acme/DAO/AcmeThing.php' => self::STUB,
        ]);
        $reporter = $this->run_(new EntityDaoStubCheck(), $context);
        $this->assertPasses($reporter);
    }

    public function testFailsOnAStaleTableName(): void
    {
        $context = $this->repo([
            'schema/AcmeThing.entityType.php' => self::SCHEMA,
            'CRM/Acme/DAO/AcmeThing.php' => str_replace(
                'civicrm_acme_thing',
                'civicrm_acme_widget',
                self::STUB,
            ),
        ]);
        $reporter = $this->run_(new EntityDaoStubCheck(), $context);
        $this->assertFails($reporter, 'stale');
    }

    public function testFailsWhenTheSchemaNamesNoClass(): void
    {
        $context = $this->repo([
            'schema/AcmeThing.entityType.php' => "<?php\nreturn ['name' => 'AcmeThing', 'table' => 'civicrm_acme_thing'];\n",
        ]);
        $reporter = $this->run_(new EntityDaoStubCheck(), $context);
        $this->assertFails($reporter, "no 'class' key");
    }

    public function testARepoWithoutEntitySchemasIsSilent(): void
    {
        $this->assertSilent($this->run_(new EntityDaoStubCheck(), $this->repo([])));
    }

    public function testATrackedRepoIgnoresAnUntrackedStub(): void
    {
        $context = $this->repo([
            'schema/AcmeThing.entityType.php' => self::SCHEMA,
        ], git: true);
        mkdir($context->path('CRM/Acme/DAO'), 0777, true);
        file_put_contents($context->path('CRM/Acme/DAO/AcmeThing.php'), self::STUB, LOCK_EX);
        $reporter = $this->run_(new EntityDaoStubCheck(), $context);
        $this->assertFails($reporter, 'ships no');
    }
}
