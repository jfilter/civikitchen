<?php

declare(strict_types=1);

abstract class ImplicitVisibility {

  var $legacy;

  static $counter = 0;

  public $first, $second;

  public readonly int $explicit;

  readonly int $implicit;

  public function __construct(private int $promoted) {
    $this->explicit = static fn (): int => $promoted;
  }

  function implicitMethod(): void {
    $closure = static function (): void {};
  }

  final public static function explicitMethod(): void {}

  abstract protected function explicitAbstract(): void;

}

interface ImplicitInterface {

  function implicitInterfaceMethod(): void;

  public function explicitInterfaceMethod(): void;

}

trait ImplicitTrait {

  private $explicit;

  function implicitTraitMethod(): void {}

}

enum ImplicitEnum {

  case One;

  const DEFAULT = self::One;

  function implicitEnumMethod(): void {}

  public function explicitEnumMethod(): void {}

}

function plainFunction(): object {
  return new class {

    var $anonymous;

    function implicitAnonymousMethod(): void {}

  };
}
