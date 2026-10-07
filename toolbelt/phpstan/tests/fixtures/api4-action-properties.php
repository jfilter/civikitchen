<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Actions;

use Civi\Api4\Generic\AbstractAction;

/** Everything a parameter can be declared as, right and wrong. */
class GreetAction extends AbstractAction
{
    /**
     * The contact to greet — @required does not stop the kernel reading it.
     *
     * @required
     */
    protected int $contactId;

    /**
     * The mandatory form that actually works: untyped, @var, @required.
     *
     * @var int
     * @required
     */
    protected $recipientId;

    /** An optional override; unset means "use the default template". */
    protected ?string $template = null;

    protected bool $dryRun = false;

    /** Nothing says a caller has to pass this — and PHP will not forgive it. */
    protected string $channel;

    /** @required */
    protected int $retries = 3;

    /** @required */
    protected ?string $locale;

    /** Nullable, no default: uninitialized until the kernel fills it. */
    protected ?string $signature;

    /** Internal state, not an API parameter. */
    protected string $_cache;

    /** Untyped: implicitly null, so never uninitialized. */
    protected $legacy;

    private string $notAParameter;

    public function getChannel(): string
    {
        return $this->channel;
    }
}

/** Not an action — the same declarations are nobody's business here. */
class PlainService
{
    protected string $channel;
}

/** A trait's properties are parameters of every action that uses it. */
trait FarewellDefaults
{
    protected string $fromTrait;

    protected string $tone = 'warm';
}

class FarewellAction extends AbstractAction
{
    use FarewellDefaults;

    protected mixed $any;

    protected ?string $maybe;

    /** @required — null is what the kernel treats as missing. */
    protected ?string $optionalNull = null;

    /** @required */
    protected array $ids = [];

    /** Not a parameter: core reads protected properties only. */
    public string $notAParam = '';

    protected string $mode;

    public function __construct($entityName = '', $actionName = '')
    {
        parent::__construct($entityName, $actionName);
        $this->mode = 'farewell';
    }
}

/** Public and uninitialized: property.uninitialized must still see it. */
class PublicFieldAction extends AbstractAction
{
    public string $notAParam;
}

/** A getter that guards the read: ValidateFieldsSubscriber calls it instead of __call. */
class GuardedGetterAction extends AbstractAction
{
    protected ?string $label;

    protected ?string $note;

    protected string $plain;

    public function getLabel(): ?string
    {
        return $this->label ?? null;
    }

    public function getNote(): ?string
    {
        return isset($this->note) ? $this->note : null;
    }

    public function getPlain(): string
    {
        return $this->plain;
    }

    protected string $initialised;

    protected array $lazy;

    protected ?string $negated;

    protected array $halfLazy;

    public function getInitialised(): string
    {
        $this->initialised ??= '';

        return $this->initialised;
    }

    public function getLazy(): array
    {
        if (!isset($this->lazy)) {
            $this->lazy = [];
        }

        return $this->lazy;
    }

    public function getNegated(): ?string
    {
        return !isset($this->negated) ? null : $this->negated;
    }

    public function getHalfLazy(): array
    {
        if (!isset($this->halfLazy)) {
            $this->negated = null;
        }

        return $this->halfLazy;
    }

    protected ?string $emptyTernary;

    protected ?string $notEmpty;

    protected array $emptyLazy;

    protected string $throwing;

    protected string $returning;

    protected string $readInExit;

    protected ?string $emptyWrongBranch;

    public function getEmptyTernary(): ?string
    {
        return empty($this->emptyTernary) ? null : $this->emptyTernary;
    }

    public function getNotEmpty(): ?string
    {
        return !empty($this->notEmpty) ? $this->notEmpty : null;
    }

    public function getEmptyLazy(): array
    {
        if (empty($this->emptyLazy)) {
            $this->emptyLazy = [];
        }

        return $this->emptyLazy;
    }

    public function getThrowing(): string
    {
        if (!isset($this->throwing)) {
            throw new \RuntimeException('throwing is not set');
        }

        return $this->throwing;
    }

    public function getReturning(): string
    {
        if (empty($this->returning)) {
            return '';
        }

        return $this->returning;
    }

    public function getReadInExit(): string
    {
        if (!isset($this->readInExit)) {
            return $this->readInExit;
        }

        return '';
    }

    public function getEmptyWrongBranch(): ?string
    {
        return empty($this->emptyWrongBranch) ? $this->emptyWrongBranch : null;
    }

    protected ?string $ifIsset;

    protected ?string $ifNotEmpty;

    protected string $assignThenRead;

    protected ?string $elseRead;

    protected ?string $trimmed;

    public function getIfIsset(): ?string
    {
        if (isset($this->ifIsset)) {
            return $this->ifIsset;
        }

        return null;
    }

    public function getIfNotEmpty(): ?string
    {
        if (!empty($this->ifNotEmpty)) {
            return $this->ifNotEmpty;
        }

        return null;
    }

    public function getAssignThenRead(): string
    {
        if (!isset($this->assignThenRead)) {
            $this->assignThenRead = 'x';

            return $this->assignThenRead;
        }

        return $this->assignThenRead;
    }

    public function getElseRead(): ?string
    {
        if (isset($this->elseRead)) {
            return null;
        } else {
            return $this->elseRead;
        }
    }

    public function getTrimmed(): ?string
    {
        return !empty(trim($this->trimmed)) ? 'y' : null;
    }

    protected ?string $andGuard;

    protected ?string $andIf;

    protected ?string $orExit;

    protected ?string $andCall;

    protected array $elseifAfterInit;

    protected ?string $matchArm;

    protected string $nestedInit;

    protected ?string $orWrong;

    public function getAndGuard(): ?string
    {
        return isset($this->andGuard) && $this->andGuard !== '' ? $this->andGuard : null;
    }

    public function getAndIf(): ?string
    {
        if (isset($this->andIf) && $this->andIf !== '') {
            return $this->andIf;
        }

        return null;
    }

    public function getOrExit(): ?string
    {
        if (!isset($this->orExit) || $this->orExit === '') {
            return null;
        }

        return $this->orExit;
    }

    public function getAndCall(): bool
    {
        return isset($this->andCall) && strlen($this->andCall) > 0;
    }

    public function getElseifAfterInit(): array
    {
        if (!isset($this->elseifAfterInit)) {
            $this->elseifAfterInit = [];
        } elseif ($this->elseifAfterInit === []) {
            $this->elseifAfterInit = [1];
        }

        return $this->elseifAfterInit;
    }

    public function getMatchArm(): ?string
    {
        return match (true) {
            isset($this->matchArm) => $this->matchArm,
            default => null,
        };
    }

    public function getNestedInit(): string
    {
        if (!isset($this->nestedInit)) {
            if (PHP_INT_SIZE > 4) {
                $this->nestedInit = 'a';
            } else {
                $this->nestedInit = 'b';
            }
        }

        return $this->nestedInit;
    }

    public function getOrWrong(): bool
    {
        return isset($this->orWrong) || $this->orWrong === '';
    }

    protected ?string $matchDefault;

    protected string $noElse;

    protected ?string $andWrong;

    protected ?string $elseifGuard;

    public function getMatchDefault(): ?string
    {
        return match (true) {
            isset($this->matchDefault) => 'set',
            default => $this->matchDefault,
        };
    }

    public function getNoElse(): string
    {
        if (PHP_INT_SIZE > 4) {
            $this->noElse = 'a';
        } elseif (PHP_INT_SIZE > 2) {
            $this->noElse = 'b';
        }

        return $this->noElse;
    }

    public function getAndWrong(): bool
    {
        return !isset($this->andWrong) && $this->andWrong === '';
    }

    public function getElseifGuard(): ?string
    {
        if (PHP_INT_SIZE < 4) {
            return null;
        } elseif (isset($this->elseifGuard)) {
            return $this->elseifGuard;
        }

        return null;
    }

    protected ?string $matchUnsetFirst;

    protected ?string $notCompound;

    protected ?string $wordAnd;

    protected ?string $matchUnsetRead;

    public function getMatchUnsetFirst(): ?string
    {
        return match (true) {
            !isset($this->matchUnsetFirst) => null,
            $this->matchUnsetFirst === '' => 'empty',
            default => $this->matchUnsetFirst,
        };
    }

    public function getNotCompound(): ?string
    {
        if (!(isset($this->notCompound) && $this->notCompound !== '')) {
            return null;
        }

        return $this->notCompound;
    }

    public function getWordAnd(): bool
    {
        return isset($this->wordAnd) and $this->wordAnd !== '';
    }

    public function getMatchUnsetRead(): ?string
    {
        return match (true) {
            !isset($this->matchUnsetRead) => $this->matchUnsetRead,
            default => null,
        };
    }

    protected ?string $elseifUnset;

    protected ?string $elseifUnsetExit;

    protected ?\stdClass $writeThrough;

    public function getElseifUnset(): ?string
    {
        if (PHP_INT_SIZE < 4) {
            return '1';
        } elseif (!isset($this->elseifUnset)) {
            return null;
        } else {
            return $this->elseifUnset;
        }
    }

    public function getElseifUnsetExit(): ?string
    {
        if (PHP_INT_SIZE < 4) {
            return '1';
        } elseif (!isset($this->elseifUnsetExit)) {
            return null;
        }

        return $this->elseifUnsetExit;
    }

    public function getWriteThrough(): ?\stdClass
    {
        $this->writeThrough->y = 1;

        return $this->writeThrough ?? null;
    }

    protected ?string $setBranch;

    protected int $setBranchThrow;

    protected ?array $notEmptyBranch;

    protected ?int $elseifSet;

    protected array $offsetInit;

    protected array $offsetCoalesce;

    public function getSetBranch(): ?string
    {
        if (isset($this->setBranch)) {
            error_log('given');
        } else {
            $this->setBranch = 'd';
        }

        return $this->setBranch;
    }

    public function getSetBranchThrow(): int
    {
        if (isset($this->setBranchThrow)) {
            error_log('given');
        } else {
            throw new \RuntimeException('setBranchThrow required');
        }

        return $this->setBranchThrow;
    }

    public function getNotEmptyBranch(): ?array
    {
        if (!empty($this->notEmptyBranch)) {
            error_log('given');
        } else {
            $this->notEmptyBranch = [];
        }

        return $this->notEmptyBranch;
    }

    public function getElseifSet(): ?int
    {
        if (PHP_INT_SIZE < 2) {
            return null;
        } elseif (isset($this->elseifSet)) {
            error_log('given');
        } else {
            $this->elseifSet = 1;
        }

        return $this->elseifSet;
    }

    public function getOffsetInit(): array
    {
        $this->offsetInit['a'] = 1;

        return $this->offsetInit;
    }

    public function getOffsetCoalesce(): array
    {
        $this->offsetCoalesce['a'] ??= 1;

        return $this->offsetCoalesce;
    }

    protected array $offsetIsset;

    protected ?string $issetFalse;

    protected ?string $emptyNotTrue;

    protected ?string $issetTrueWrong;

    public function getOffsetIsset(): ?array
    {
        if (isset($this->offsetIsset['k'])) {
            return $this->offsetIsset;
        }

        return null;
    }

    public function getIssetFalse(): ?string
    {
        if (isset($this->issetFalse) === false) {
            return null;
        }

        return $this->issetFalse;
    }

    public function getEmptyNotTrue(): ?string
    {
        return empty($this->emptyNotTrue) != true ? $this->emptyNotTrue : null;
    }

    public function getIssetTrueWrong(): ?string
    {
        if (isset($this->issetTrueWrong) === true) {
            return null;
        }

        return $this->issetTrueWrong;
    }
}
