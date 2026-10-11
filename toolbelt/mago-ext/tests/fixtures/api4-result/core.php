<?php

// Core's declarations as core ships them; the extension and toolbelt/mago/patches type them.

namespace Civi\Api4\Generic {

  abstract class AbstractAction implements \ArrayAccess {

    /**
     * @return \Civi\Api4\Generic\Result
     */
    public function execute() {}

  }

}

namespace {

  /**
   * @param string|int|array $index
   * @return \Civi\Api4\Generic\Result
   */
  function civicrm_api4(string $entity, string $action, array $params = [], $index = NULL) {}

}
