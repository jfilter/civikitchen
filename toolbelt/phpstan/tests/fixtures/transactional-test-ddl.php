<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\TransactionalTests;

use Civi\Api4\CustomField;
use Civi\Test\TransactionalInterface;
use CiviKitchen\Fixtures\Support\ExtensionManager;
use CiviKitchen\Fixtures\Support\TestCase;

final class WidgetTest extends TestCase implements TransactionalInterface
{
    protected function setUp(): void
    {
        CustomField::create(false)
            ->addValue('label', 'Widget size')
            ->execute();
        \civicrm_api4('CustomGroup', 'create', ['values' => ['name' => 'widget']]);
        \CRM_Core_DAO::executeQuery('CREATE TABLE civicrm_widget (id INT)');
        \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_widget');
        $manager = new ExtensionManager();
        $manager->install('org.example.widget');
    }

    public function testSomething(): void
    {
        \CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_contact ADD COLUMN x INT');
    }

    /** DML is transactional and stays where it is. */
    public function testWritesRows(): void
    {
        \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_widget (id) VALUES (1)');
        \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_widget');
    }

    /** Not a test method and not setUp — outside the transaction. */
    public function setUpHeadless(): void
    {
        \CRM_Core_DAO::executeQuery('CREATE TABLE civicrm_widget (id INT)');
        CustomField::create(false)->execute();
    }

    public function testViaHelper(): void
    {
        $this->ensureSchema();
    }

    /** One call away from a test method is still inside the transaction. */
    private function ensureSchema(): void
    {
        \CRM_Core_DAO::executeQuery('RENAME TABLE civicrm_widget TO civicrm_gadget');
    }
}

/** Without the marker interface there is no transaction to break. */
final class PlainWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        \CRM_Core_DAO::executeQuery('CREATE TABLE civicrm_widget (id INT)');
        CustomField::create(false)->execute();
    }
}
