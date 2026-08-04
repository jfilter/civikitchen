<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\SchemaCatalog;
use CiviKitchen\PHPStan\SqlSchema;
use CiviKitchen\PHPStan\SqlTableMethodCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<SqlTableMethodCallRule>
 */
final class SqlTableMethodCallRuleTest extends RuleTestCase
{
    public function testTablesInTheBuilderAndInDaoQueriesAreChecked(): void
    {
        $version = SchemaCatalog::CORE_VERSION;
        $this->analyse(
            [__DIR__ . '/fixtures/sql-tables.php'],
            [
                ["SQL table civicrm_emails does not exist in CiviCRM $version — ->join()", 41],
                ["SQL table civicrm_widget_rule does not exist in CiviCRM $version — ->query()", 44],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new SqlTableMethodCallRule(new SqlSchema(__DIR__ . '/fixtures/no-such-repo'));
    }
}
