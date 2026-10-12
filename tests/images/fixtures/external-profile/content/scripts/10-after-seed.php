<?php
// Scripts run after config and seed: the seed contact is already there.
$count = \Civi\Api4\Contact::get(FALSE)->addWhere('external_identifier', '=', 'acme-ada')->execute()->count();
if ($count !== 1) {
  throw new \RuntimeException("content fixture script ran before the seed (acme-ada count {$count})");
}
echo "  content fixture script saw the seed contact\n";
