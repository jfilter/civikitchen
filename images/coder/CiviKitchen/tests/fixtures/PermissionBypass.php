<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures;

use Civi\Api4\Contact;

/**
 * Fixture: the two literal permission-bypass forms, plus near misses.
 */
class PermissionBypass {

  public function bypasses(): void {
    Contact::get()->setCheckPermissions(FALSE)->execute();
    Contact::get()->setCheckPermissions(false)->execute();
    civicrm_api4('Contact', 'get', ['checkPermissions' => FALSE]);
    civicrm_api4('Contact', 'get', [
      'where' => [['id', '=', 1]],
      'checkPermissions' => false,
    ]);
  }

  public function nearMisses(): void {
    // Permissions left on, or decided elsewhere: not this sniff's business.
    Contact::get()->setCheckPermissions(TRUE)->execute();
    Contact::get()->setCheckPermissions($this->systemContext())->execute();
    civicrm_api4('Contact', 'get', ['checkPermissions' => TRUE]);
    // The array key outside a civicrm_api4() call — a plain params array the
    // sniff must not follow through a variable.
    $params = ['checkPermissions' => FALSE];
    $this->consume($params);
  }

  private function systemContext(): bool {
    return TRUE;
  }

  /**
   * @param array<string, mixed> $params
   */
  private function consume(array $params): void {
  }

}
