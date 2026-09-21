<?php

declare(strict_types = 1);

namespace Civi\Ckmonoaddon;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
final class ShoutTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  public function testUsesTheSiblingExtension(): void {
    self::assertSame('HELLO FROM CKMONOBASE', (new Shout())->text());
  }

}
