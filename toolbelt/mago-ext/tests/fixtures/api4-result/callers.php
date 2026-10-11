<?php

declare(strict_types=1);

use Civi\Api4\Generic\Result;

final class WidgetRows {

  public function firstId(Result $result): mixed {
    return $result[0]['id'] ?? NULL;
  }

  public function rows(Result $result): array {
    return (array) $result;
  }

  public function append(Result $result): void {
    $result[] = ['id' => 1];
  }

  public function plain(\stdClass $object): array {
    return (array) $object;
  }

}

final class Ledger extends \ArrayObject {
}

function ledger_first(Ledger $ledger): mixed {
  return $ledger[0];
}
