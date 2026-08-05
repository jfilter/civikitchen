<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\SchemaCatalog;
use CiviKitchen\PHPStan\SqlSchema;
use CiviKitchen\PHPStan\SqlTableStaticCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<SqlTableStaticCallRule>
 */
final class SqlTableStaticCallRuleTest extends RuleTestCase
{
    private ?SqlSchema $schema = null;

    public function testUnknownCoreTablesAreReported(): void
    {
        $version = SchemaCatalog::CORE_VERSION;
        $this->schema = new SqlSchema(__DIR__ . '/fixtures/no-such-repo');
        $this->analyse(
            [__DIR__ . '/fixtures/sql-tables.php'],
            [
                ["SQL table civicrm_widget_rule does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 12],
                ["SQL table civicrm_contakt does not exist in CiviCRM $version — CRM_Core_DAO::singleValueQuery()", 13],
                ["SQL table civicrm_emails does not exist in CiviCRM $version — CRM_Core_DAO::executeUnbufferedQuery()", 14],
                ["SQL table civicrm_gadget does not exist in CiviCRM $version — CRM_Utils_SQL_Select::from()", 17],
            ],
        );
    }

    /** The repo's own schema and the configured list take names off the list. */
    public function testDeclaredTablesAreSilent(): void
    {
        $version = SchemaCatalog::CORE_VERSION;
        $this->schema = new SqlSchema(__DIR__ . '/fixtures/repo', ['civicrm_contakt', 'civicrm_gadget']);
        $this->analyse(
            [__DIR__ . '/fixtures/sql-tables.php'],
            [
                ["SQL table civicrm_emails does not exist in CiviCRM $version — CRM_Core_DAO::executeUnbufferedQuery()", 14],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new SqlTableStaticCallRule($this->schema ?? new SqlSchema(__DIR__ . '/fixtures/no-such-repo'));
    }
}
