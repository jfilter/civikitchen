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
}
