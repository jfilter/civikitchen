<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\EmptyDefaults;

use Civi\Api4\Generic\AbstractAction;

trait TraitDefaults
{
    /** @required */
    protected ?string $traitNullable;

    /** @required */
    protected string $traitSet = 'x';

    /** @required */
    protected array $traitEmpty = [];

    /** @required */
    protected bool $traitFalse = false;

    /** @required */
    protected string $traitString = '';

    /** @required */
    protected string $traitWrapped = ("");

    /** @required */
    protected string $traitBlank = ' ';
}

/** Defaults the kernel treats as missing keep @required enforceable. */
class DefaultsAction extends AbstractAction
{
    use TraitDefaults;

    /** @required */
    protected string $single = '';

    /** @required */
    protected string $double = "";

    /** @required */
    protected bool $flag = FALSE;

    /** @required */
    protected array $legacy = array();

    /** @required */
    protected ?int $none = NULL;

    /** @required */
    protected string $set = 'x';

    /** @required */
    protected string $wrapped = ( '' );

    /** @required */
    protected string $blank = " ";
}

trait InnerSet
{
    /** @required */
    protected string $innerSet = 'x';

    protected string $innerUntyped;
}

trait OuterSet
{
    use InnerSet;
}

class NestedTraitAction extends AbstractAction
{
    use OuterSet;
}
