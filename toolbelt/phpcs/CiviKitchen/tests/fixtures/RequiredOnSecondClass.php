<?php

// Fixture: the guarded action is not the first class in the file, and a
// trait it uses carries @required too (outside the class body: not seen).

class RequiredHelper {

  /**
   * @var string
   */
  protected $note = '';

}

trait RequiredTrait {

  /**
   * @var string|null
   * @required
   */
  protected $token = NULL;

}

class RequiredOnIntake extends \Civi\Api4\Generic\AbstractAction {

  use RequiredTrait;

  /**
   * @var string|null
   * @required
   */
  protected $submission_uid = NULL;

}
