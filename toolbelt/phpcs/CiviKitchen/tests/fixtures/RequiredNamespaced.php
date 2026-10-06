<?php

// Fixture: two classes named RequiredOnIntake; the qualified externalActions
// entry guards only Acme\Api\RequiredOnIntake.

namespace Other {

  class RequiredOnIntake {

    /**
     * @var string|null
     * @required
     */
    protected $lookup = NULL;

  }

}

namespace Acme\Api {

  class RequiredOnIntake extends \Civi\Api4\Generic\AbstractAction {

    /**
     * @var string|null
     * @required
     */
    protected $submission_uid = NULL;

  }

}
