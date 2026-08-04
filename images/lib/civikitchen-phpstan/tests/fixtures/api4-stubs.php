<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * Just enough of the APIv4 surface for the rule fixtures to resolve.
 *
 * The rules read the AST, not these types, but an unresolvable class turns
 * the fixture into a pile of unrelated phpstan errors — and the one rule
 * path that does read a type needs core's generated class layout,
 * `Civi\Api4\Action\<Entity>\<Action>`.
 */
class Contact
{
    public static function get(bool $checkPermissions = true): Action\Contact\Get
    {
        return new Action\Contact\Get();
    }
}

class CustomField
{
    public static function create(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}

class CustomGroup
{
    public static function create(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}

namespace Civi\Api4\Action\Contact;

class Get extends \Civi\Api4\Generic\DummyAction {}
