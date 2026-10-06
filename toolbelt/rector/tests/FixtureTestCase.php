<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Tests;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * One rule per test class: config/<Class>.php registers it, and every
 * Fixture/<Class>/*.php.inc is an input, `-----`, and the expected output
 * (no separator: the rule must leave the file alone).
 */
abstract class FixtureTestCase extends AbstractRectorTestCase {

  /**
   * @dataProvider provideFixtures
   */
  public function testFixture(string $file): void {
    $this->doTestFile($file);
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function provideFixtures(): iterable {
    foreach (glob(__DIR__ . '/Fixture/' . self::shortName() . '/*.php.inc') ?: [] as $file) {
      yield basename($file) => [$file];
    }
  }

  public function provideConfigFilePath(): string {
    return __DIR__ . '/config/' . self::shortName() . '.php';
  }

  private static function shortName(): string {
    return substr(strrchr(static::class, '\\') ?: '', 1, -strlen('Test'));
  }

}
