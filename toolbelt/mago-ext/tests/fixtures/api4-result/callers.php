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

  public function indexedRows(Result $result): array {
    return (array) $result->indexBy('id');
  }

  public function append(Result $result): void {
    $result[] = ['id' => 1];
  }

}

final class WidgetResult extends Result {
}

final class Ledger extends \ArrayObject {
}

function ledger_rows(Ledger $ledger): array {
  return (array) $ledger;
}

function ledger_first(Ledger $ledger): mixed {
  return $ledger[0];
}
