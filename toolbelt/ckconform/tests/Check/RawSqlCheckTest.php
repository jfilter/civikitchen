<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\RawSqlCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class RawSqlCheckTest extends CheckTestCase
{
    public function testWarnsOnAnUnjustifiedSink(): void
    {
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_count() {
              return CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
            }
            PHP,
        ]);
        $reporter = $this->run_(new RawSqlCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'raw SQL needs a reason');
    }

    public function testAJustifiedSinkIsSilent(): void
    {
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_count() {
              // ckconform-ignore raw-sql -- window aggregate across three custom tables; APIv4 cannot express it
              return CRM_Core_DAO::executeQuery('SELECT 1');
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new RawSqlCheck(), $context));
    }

    public function testAnUpgraderNeedsNoJustification(): void
    {
        // The upgrade framework's own guidance recommends low-level SQL there.
        $context = $this->repo(['CRM/Fixture/Upgrader.php' => <<<'PHP'
            <?php
            class CRM_Fixture_Upgrader {
              public function upgrade_1001(): bool {
                CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_fixture ADD COLUMN x INT');
                return TRUE;
              }
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new RawSqlCheck(), $context));
    }

    public function testInterpolationWarnsEvenInAnUpgrader(): void
    {
        $context = $this->repo(['CRM/Fixture/Upgrader.php' => <<<'PHP'
            <?php
            class CRM_Fixture_Upgrader {
              public function upgrade_1002(string $table): bool {
                CRM_Core_DAO::executeQuery("DROP TABLE {$table}");
                return TRUE;
              }
            }
            PHP,
        ]);
        $reporter = $this->run_(new RawSqlCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'interpolates variables into the SQL string');
    }

    public function testConcatenationCountsAsInterpolation(): void
    {
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_fetch($ids) {
              return CRM_Core_DAO::executeQuery('SELECT * FROM t WHERE id IN (' . $ids . ')');
            }
            PHP,
        ]);
        $this->assertWarns($this->run_(new RawSqlCheck(), $context), 'interpolates');
    }

    public function testABareSqlVariableIsNotInterpolation(): void
    {
        // Where $sql was built is beyond a token scan — only the visible
        // composition is judged; the plain sink warning still applies.
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_run($sql, $params) {
              return CRM_Core_DAO::executeQuery($sql, $params);
            }
            PHP,
        ]);
        $reporter = $this->run_(new RawSqlCheck(), $context);
        $this->assertWarns($reporter, 'raw SQL needs a reason');
        self::assertStringNotContainsString('interpolates', implode("\n", $reporter->messages('warn')));
    }

    public function testParameterisedLiteralSqlGetsOnlyTheJustificationWarning(): void
    {
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_lookup($id) {
              return CRM_Core_DAO::singleValueQuery('SELECT name FROM t WHERE id = %1', [1 => [$id, 'Positive']]);
            }
            PHP,
        ]);
        $reporter = $this->run_(new RawSqlCheck(), $context);
        $this->assertWarns($reporter, 'raw SQL needs a reason');
        self::assertStringNotContainsString('interpolates', implode("\n", $reporter->messages('warn')));
    }

    public function testSqlBuildersAreNotSinks(): void
    {
        $context = $this->repo(['CRM/Fixture/Query.php' => <<<'PHP'
            <?php
            function fixture_select() {
              return CRM_Utils_SQL_Select::from('civicrm_contact')->where('id = #id', ['id' => 5])->execute();
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new RawSqlCheck(), $context));
    }
}
