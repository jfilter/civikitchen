<?php

// Types mago lays over core's declaration of the same symbol; mago reads nothing
// else from this file, so core's members stay as core declares them.

/**
 * With an $index the Result holds one row's fields or one column, not rows.
 *
 * @param string|int|array|null $index
 * @return ($index is null ? \Civi\Api4\Generic\Result<array<string, mixed>> : \Civi\Api4\Generic\Result)
 */
function civicrm_api4(string $entity, string $action, array $params = [], $index = NULL) {}
