<?php

declare(strict_types = 1);

namespace Civi\Ckmonoaddon;

use Civi\Ckmonobase\Greeting;

/**
 * Uses a class from the extension this one <requires>, which only resolves
 * when the sibling is mounted and enabled in the same site.
 */
final class Shout {

  public function text(): string {
    return strtoupper((new Greeting())->text());
  }

}
