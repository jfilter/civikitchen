<?php

// Fixture: an externally reachable APIv4 action using @required — flagged
// ONLY when the ruleset lists 'RequiredOnIntake' in externalActions.

class RequiredOnIntake extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @var string|null
   * @required
   */
  protected $submission_uid = NULL;

}
