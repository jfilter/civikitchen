<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Initialization;

use Civi\Api4\Generic\AbstractAction;

trait FarewellDefaults
{
    protected string $fromTrait;
}

class FarewellAction extends AbstractAction
{
    use FarewellDefaults;

    protected string $mode;

    protected string $_cache;

    private string $secret;

    public string $notAParam;

    public function __construct()
    {
    }
}

class NestedAction extends FarewellAction
{
    protected int $limit;
}

class PlainService
{
    protected string $channel;

    public function __construct()
    {
    }
}

trait InnerDefaults
{
    protected string $fromInner;
}

trait OuterDefaults
{
    use InnerDefaults;
}

class GreetingAction extends AbstractAction
{
    use OuterDefaults;

    public function __construct()
    {
    }
}
