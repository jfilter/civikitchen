<?php

declare(strict_types=1);

namespace Civi\Api4\Generic;

/** Core's APIv4 result: an ArrayObject over the returned rows. */
class Result extends \ArrayObject {

  public function first(): ?array {
    return $this->getArrayCopy()[0] ?? NULL;
  }

}
