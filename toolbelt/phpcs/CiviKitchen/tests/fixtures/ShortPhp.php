<?php

declare(strict_types = 1);

// Fixture for CiviKitchen.Files.MaxFileLength: eleven lines, one over the
// armed cap (maxLines=10), so it trips the sniff on line 1.

function civikitchen_fixture_short(): int {
  return 1;
}

