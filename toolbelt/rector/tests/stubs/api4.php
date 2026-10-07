<?php

declare(strict_types = 1);

namespace Civi\Api4\Generic;

abstract class AbstractEntity {}

abstract class DAOEntity extends AbstractEntity {}

namespace Civi\Api4;

/**
 * Core's three shapes of entity class: declared actions, magic actions
 * through __callStatic, and CustomValue, whose actions take the group first.
 * Setting is not a DAO entity: its rows have no id.
 */
class Contact extends Generic\DAOEntity {

  public static function get($checkPermissions = TRUE) {}

  public static function __callStatic($action, $args) {}

}

class CustomValue {

  public static function get($customGroup, $checkPermissions = TRUE) {}

}

class Setting extends Generic\AbstractEntity {

  public static function get($checkPermissions = TRUE) {}

}

namespace CiviKitchen\Rector\Tests\Fixture;

/**
 * Callees whose parameters the fixtures pass a result into.
 */
class Helper {

  public static function show(array $rows): void {}

  public static function keep(array &$rows): void {}

  public function __call($name, $args) {}

}
