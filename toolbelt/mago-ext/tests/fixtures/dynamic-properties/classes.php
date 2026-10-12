<?php

declare(strict_types=1);

#[AllowDynamicProperties]
class DataObject {}

class Dao extends DataObject {}

/** @property string $params */
class Bao extends Dao {}

/** @property string $params */
class Sealed {}

function write(Bao $bao, Sealed $sealed): void {
  $bao->params = 'a';
  $sealed->params = 'b';
}

function read(Bao $bao, Sealed $sealed): string {
  return $bao->params . $sealed->params;
}
