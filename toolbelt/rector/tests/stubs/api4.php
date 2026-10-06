<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * Core's three shapes of entity class: declared actions, magic actions
 * through __callStatic, and CustomValue, whose actions take the group first.
 */
class Contact {

  public static function get($checkPermissions = TRUE) {}

  public static function __callStatic($action, $args) {}

}

class CustomValue {

  public static function get($customGroup, $checkPermissions = TRUE) {}

}
