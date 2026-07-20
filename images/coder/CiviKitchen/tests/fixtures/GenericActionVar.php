<?php

// Fixture: generics in runtime-parsed @var tags of APIv4 action params must
// be flagged; plain @var with @phpstan-var, inline @var inside methods, and
// generic @var in NON-action classes must not.

namespace Civi\Api4\Action\Fixture;

class Fixture_Api4_Import extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @var array<int, array<string, mixed>>
   */
  protected array $rows = [];

  /**
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $config = [];

  public function _run(): void {
    /** @var array<string, mixed> $row */
    $row = $this->rows[0] ?? [];
    $this->config = $row;
  }

}

class Fixture_Api4_Basic extends BasicBatchAction {

  /**
   * @var array<string, mixed>|null
   */
  protected ?array $options = NULL;

}

class Fixture_Plain_Service extends SomeServiceBase {

  /**
   * @var array<int, string>
   */
  protected array $fine = [];

}
