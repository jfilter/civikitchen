<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * Just enough of the APIv4 surface for the rule fixtures to resolve.
 *
 * The rules read the AST, not these types, but an unresolvable class turns
 * the fixture into a pile of unrelated phpstan errors. Like core, most
 * actions return a generic action class that names no entity.
 */
class Contact
{
    public static function get(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }

    public static function create(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }

    public static function getFields(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}

class Activity
{
    public static function get(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}

class Group
{
    public static function get(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}

class CustomField
{
    public static function create(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }

    public static function update(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }

    public static function delete(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }

    public static function get(bool $checkPermissions = true): Generic\DummyAction
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

    public static function delete(bool $checkPermissions = true): Generic\DummyAction
    {
        return new Generic\DummyAction();
    }
}
