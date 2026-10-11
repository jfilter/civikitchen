<?php

// Read by mago in place of core's file at the same path (ckanalyze excludes
// core's copy). Core's own types, plus the row type as a template parameter.

namespace Civi\Api4\Generic;

use Psr\Log\LogLevel;

/**
 * An action may fill its Result with anything, so rows default to mixed; the
 * civikitchen mago extension and toolbelt/mago/patches type the rows core returns.
 *
 * @template-covariant TRow = mixed
 * @extends \ArrayObject<array-key, TRow>
 */
class Result extends \ArrayObject implements \JsonSerializable {

  /**
   * @var string
   */
  public $entity;

  /**
   * @var string
   */
  public $action;

  /**
   * @var array
   */
  public $debug;

  /**
   * @var int
   */
  public $version = 4;

  /**
   * @var int
   */
  public $rowCount;

  /**
   * @var int
   */
  protected $matchedCount;

  /**
   * @return TRow|null
   */
  public function first() {}

  /**
   * @return TRow|null
   */
  public function last() {}

  /**
   * @return TRow
   * @throws \CRM_Core_Exception
   */
  public function single() {}

  /**
   * @param int $index
   * @return TRow|null
   */
  public function itemAt($index) {}

  /**
   * @param string $key
   * @return $this
   * @throws \CRM_Core_Exception
   */
  public function indexBy($key) {}

  public function count(): int {}

  public function countFetched(): int {}

  /**
   * @throws \CRM_Core_Exception
   */
  public function countMatched(): int {}

  public function setCountMatched(int $c) {}

  public function hasCountMatched(): bool {}

  /**
   * @return \Civi\Api4\Generic\Error[]
   */
  public function getErrors(): array {}

  /**
   * @param \Civi\Api4\Generic\Error[] $errors
   * @return $this
   */
  public function setErrors(array $errors) {}

  /**
   * @return $this
   */
  public function addError(string $message, bool $log = FALSE, int|string $code = 0, string $title = '', string $level = LogLevel::ERROR, array $metadata = []) {}

  public function hasErrors(): bool {}

  public function getMaxErrorLevel(): ?string {}

  public function isBlockingError(): bool {}

  /**
   * @param string $columnName
   * @param string|null $indexBy
   */
  public function column($columnName, $indexBy = NULL): array {}

  /**
   * Renames the keys within each row: the rows stay arrays, their shape is lost.
   *
   * @param array|callable $map
   * @return static<array<array-key, mixed>>
   */
  public function rekey($map) {}

  public function jsonSerialize(): array {}

}
