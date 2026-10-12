<?php

namespace Civi\Api4\Generic;

// Core documents a closed key set with `type` as a string; getInfo() returns
// `type` as a list and adds keys per DAO, trait and class docblock.
abstract class AbstractEntity implements EntityInterface {

  /**
   * @return array<string, mixed>
   */
  public static function getInfo() {}

}
