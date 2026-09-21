<?php

declare(strict_types = 1);

namespace Civi\Ckmonobase;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
final class GreetingTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return ck_headless()->apply();
  }

  public function testText(): void {
    self::assertSame('hello from ckmonobase', (new Greeting())->text());
  }

}
