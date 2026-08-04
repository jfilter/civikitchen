<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Actions;

use Civi\Api4\Generic\AbstractAction;

/** Everything a parameter can be declared as, right and wrong. */
class GreetAction extends AbstractAction
{
    /**
     * The contact to greet.
     *
     * @required
     */
    protected int $contactId;

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
