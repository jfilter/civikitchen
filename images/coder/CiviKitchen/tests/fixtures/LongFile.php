<?php

declare(strict_types=1);

namespace CiviKitchen\FixtureLong;

// Fixture for CiviKitchen.Files.MaxFileLength. Deliberately short but longer
// than the armed test cap (maxLines=10), so it trips the sniff on line 1 under
// max-file-length-ruleset.xml while staying well within the default 1000 cap
// (zero findings there). Kept clean of every other CiviKitchen footgun sniff.

class LongishFixture {

  public function answer(): int {
    return 42;
  }

}
