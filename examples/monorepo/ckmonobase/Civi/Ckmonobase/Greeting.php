<?php

declare(strict_types = 1);

namespace Civi\Ckmonobase;

/**
 * The one piece of behaviour ckmonoaddon builds on.
 */
final class Greeting {

  public function text(): string {
    return 'hello from ckmonobase';
  }

}
