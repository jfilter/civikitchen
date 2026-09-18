<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * What ck_headless() has to hold true on a COLD and on a WARM scratch DB.
 * The suite is run twice against the same civicrm_test by the boot test; the
 * second run is the interesting one.
 *
 * @group headless
 */
class CkHeadlessContractTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  /**
   * Signing CoreSchemaStep drops every core foreign key; a warm apply() used
   * to return before re-adding them, leaving the schema without cascades.
   */
  public function testCoreForeignKeysSurvive(): void {
    $count = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
       WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $this->assertGreaterThan(200, $count, 'core foreign keys are missing from the scratch database');
    $this->assertTrue(
      CRM_Core_BAO_SchemaHandler::checkFKExists('civicrm_email', 'FK_civicrm_email_contact_id'),
      'the ON DELETE CASCADE constraint tests rely on is gone'
    );
  }

  /**
   * Building the environment queues managed-entity errors in the session.
   * No test may start with them in its status buffer.
   */
  public function testNoBootstrapStatusMessages(): void {
    $status = CRM_Core_Session::singleton()->getStatus(FALSE);
    $this->assertSame([], $status, 'the environment build left status messages behind');
  }

}
