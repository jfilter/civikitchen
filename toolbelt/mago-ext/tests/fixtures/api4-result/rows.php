<?php

declare(strict_types=1);

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

final class LedgerRows {

  public function each(AbstractAction $action): string {
    foreach ($action->execute() as $row) {
      return $row;
    }
    return '';
  }

  public function single(AbstractAction $action): string {
    return $action->execute()->single();
  }

  public function first(AbstractAction $action): string {
    return $action->execute()->first();
  }

  public function fetched(): string {
    return civicrm_api4('Ledger', 'get', ['select' => ['title']])[0];
  }

  public function fields(): string {
    return civicrm_api4('Ledger', 'get', ['select' => ['title']], 0)['title'];
  }

  public function titles(): string {
    return civicrm_api4('Ledger', 'get', [], ['title'])[0];
  }

  /**
   * @return array<array-key, mixed>|null
   */
  public function unindexed(): ?array {
    return civicrm_api4('Ledger', 'get', [], NULL)->first();
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   */
  public function typedEach(Result $result): string {
    foreach ($result as $row) {
      return $row['id'];
    }
    return '';
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   */
  public function typedIndex(Result $result): string {
    return $result[0]['title'];
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   */
  public function typedIndexBy(Result $result): string {
    return $result->indexBy('id')->itemAt(0);
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   */
  public function rekeyed(Result $result): string {
    return $result->rekey(['id' => 'ledger_id'])->single();
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   */
  public function typedFirstId(Result $result): ?int {
    return $result->first()['id'] ?? NULL;
  }

  /**
   * @param Result<array{id: int, title: string|null}> $result
   * @return list<int>
   */
  public function typedIds(Result $result): array {
    $ids = [];
    foreach ($result as $row) {
      $ids[] = $row['id'];
    }
    return $ids;
  }

  /**
   * @return array<array-key, mixed>|null
   */
  public function last(AbstractAction $action): ?array {
    return $action->execute()->last();
  }

  public function append(AbstractAction $action): void {
    $result = $action->execute();
    $result[] = ['id' => 1];
  }

  public function count(AbstractAction $action): int {
    return $this->countFetched($action->execute());
  }

  public function countFetched(Result $result): int {
    return $result->countFetched() + count($result->column('id'));
  }

  public function fill(Result $result): void {
    $result['run_id'] = 5;
    $result['dry_run'] = TRUE;
    $result[] = 'done';
    $result->offsetSet('note', 'kept');
  }

  public function skipped(LedgerResult $result): array {
    return $result->rekey(['id' => 'ledger_id'])->skipped;
  }

  public function inherited(LedgerGet $get): string {
    return $get->execute()->single();
  }

  public function checked(LedgerCheck $check): array {
    return $check->execute()->skipped;
  }

  public function narrowed(AbstractAction $action): array {
    $result = $action->execute();
    return $result instanceof LedgerResult ? $result->skipped : [];
  }

}

final class LedgerResult extends Result {

  public array $skipped = [];

}

final class LedgerGet extends AbstractAction {
}

final class LedgerCheck extends AbstractAction {

  /**
   * @return LedgerResult
   */
  public function execute() {
    $result = parent::execute();
    if (!$result instanceof LedgerResult) {
      throw new \RuntimeException('execute() returns the Result class its @return names');
    }
    return $result;
  }

}
