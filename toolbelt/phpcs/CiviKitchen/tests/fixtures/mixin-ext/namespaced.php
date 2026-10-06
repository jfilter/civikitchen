<?php

// Fixture: core calls only global hook functions, also from a braced
// global namespace block.

namespace Acme\Hooks {
  function acme_civicrm_xmlMenu(&$files) {
    $files = [];
  }
}

namespace {
  function acme_civicrm_caseTypes(&$caseTypes) {
    $caseTypes = [];
  }
}
