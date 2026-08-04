<?php

// Fixture: an admin-only action using @required legitimately — never
// flagged (not in the externalActions list).

class RequiredOnImporter extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @var string|null
   * @required
   */
  protected $importFile = NULL;

}
