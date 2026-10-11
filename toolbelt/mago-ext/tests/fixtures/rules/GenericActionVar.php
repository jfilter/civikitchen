<?php

// Fixture: @var types core cannot validate on APIv4 action params must be
// flagged; types core accepts, non-params and non-action classes must not.

namespace Civi\Api4\Action\Fixture {

  use Civi\Api4\Generic\AbstractAction;

  class FixtureImport extends AbstractAction {

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $rows = [];

    /**
     * @var int[]
     */
    protected array $ids = [];

    /**
     * @var non-empty-string
     */
    protected string $label = 'x';

    /**
     * @var ?string
     */
    protected ?string $maybe = NULL;

    /**
     * @var integer
     */
    protected int $count = 0;

    /**
     * @var object
     */
    protected $thing;

    /**
     * Description first.
     *
     * @var boolean|null
     */
    protected static $legacy;

    /**
     * @var array
     * @phpstan-var array<string, mixed>
     */
    protected array $config = [];

    /**
     * @var string Use <b> for bold.
     */
    protected string $html = '';

    /**
     * @var String|NULL|int
     */
    protected $mixedCase;

    /**
     * @var array{a: list<int>}
     */
    protected array $shape = [];

    /**
     * @var array{
     *   a: list<int>,
     * }
     */
    protected array $multiLineShape = [];

    /**
     * @var array<string, int>
     */
    private array $privateCache = [];

    /**
     * @var array<string, int>
     */
    protected array $_internal = [];

    /**
     * @var array<string, int>
     */
    public array $publicState = [];

    /**
     * @var array<string, int>
     */
    protected $version = 4;

    /**
     * @var mixed
     */
    protected $anything;

    public function _run(): void {
      /** @var array<string, mixed> $row */
      $row = $this->rows[0] ?? [];
      $this->config = $row;
    }

  }

  class FixtureBatch extends BasicBatchAction {

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $options = NULL;

  }

}

namespace Civi\MyExt\Action {

  class Contact_Get extends \Civi\Api4\Action\Contact\Get {

    /**
     * @var list<int>
     */
    protected array $extra = [];

  }

  class Foo extends ExtensionBase {

    /**
     * @var string[]
     */
    protected array $names = [];

    public function _run(\Civi\Api4\Generic\Result $result): void {
      $result->exchangeArray($this->names);
    }

  }

  class PlainService extends ServiceBase {

    /**
     * @var array<int, string>
     */
    protected array $fine = [];

  }

  class Standalone {

    /**
     * @var array<int, string>
     */
    protected array $alsoFine = [];

    public function _run(): void {
    }

  }

  class NotAnAction extends \Some\Lib\Worker {

    /**
     * @var array<int, string>
     */
    protected array $map = [];

    protected function _run(): void {
    }

  }

  class EntityLike extends \Civi\Api4\Generic\DAOEntity {

    /**
     * @var array<string, mixed>
     */
    protected static $cache;

  }

}

namespace {

  /**
   * A CiviRules action: its *Action parent is not an APIv4 action.
   */
  class CRM_CivirulesActions_Fixture_Tag extends CRM_Civirules_Action {

    /**
     * @var array<string, mixed>
     */
    protected array $actionParams = [];

  }

}
