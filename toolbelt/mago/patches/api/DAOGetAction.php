<?php

namespace Civi\Api4\Generic;

// Core documents $bridge as a string, but defaults it to NULL and reads an
// array in its place as the first join condition.
class DAOGetAction extends AbstractGetAction {

  /**
   * @param string|bool $type
   * @param string|array|null $bridge
   * @param array ...$conditions
   */
  public function addJoin(string $entity, string|bool $type = 'LEFT', $bridge = NULL, ...$conditions): DAOGetAction {}

}
