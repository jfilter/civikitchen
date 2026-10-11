<?php

// Fixture: a file with code but no declare(strict_types=1). The finding is a
// whole-file fact, so it is reported on line 1 at the open tag.

function civikitchen_fixture_coerces(int $id): int {
  return $id;
}
