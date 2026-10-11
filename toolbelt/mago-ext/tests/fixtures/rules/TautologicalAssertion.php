<?php

// Fixture: assertions whose outcome literals fix must be flagged; assertions
// on real expressions, on the opposite literal, and plain function calls of
// the same name must not.

namespace Fixture;

class TautologicalAssertionTest {

  public function flagged(): void {
    $this->assertTrue(TRUE);
    self::assertTrue(true);
    $this->assertFalse(FALSE);
    $this->assertNotFalse(TRUE);
    $this->assertTrue(TRUE, 'message');
    $this->assertTrue(\TRUE);
    $this->assertTrue(/* reason */ TRUE);
    $this->assertTrue(condition: TRUE);
    $this->assertTrue(TRUE,);
    $this?->assertTrue(TRUE);
    $this->assertFalse(!TRUE);
    static::assertTrue(
      TRUE
    );
    Assert::assertTrue(TRUE);
    $this->assertTrue(!!TRUE);
    $this->assertNull(NULL);
    $this->assertSame(TRUE, TRUE);
    $this->assertEquals(1, 1);
    $this->assertSame('a', 'a', 'message');
    $this->assertEmpty([]);
    $this->assertEmpty(array());
    $this->assertSame(actual: -1, expected: -1);
    $this->assertNotTrue(FALSE);
  }

  public function clean(bool $result, array $rows): void {
    $this->expectNotToPerformAssertions();
    $this->assertTrue($result);
    $this->assertTrue(TRUE === $result);
    $this->assertFalse(isset($rows['missing']));
    $this->assertTrue(FALSE);
    $this->assertTrue(!TRUE);
    $this->assertFalse(!FALSE);
    $this->assertTrue($result, 'TRUE');
    $this->assertTrue(message: 'TRUE', condition: $result);
    $this->assertSame(TRUE, $result);
    $this->assertSame(1, 2);
    $this->assertSame(1, -1);
    $this->assertEquals('a', "a");
    $this->assertSame(PHP_EOL, PHP_EOL);
    $this->assertNull($result);
    $this->assertNotNull(NULL);
    $this->assertEmpty([0]);
    $this->assertEmpty($rows);
    assertTrue(TRUE);
  }

}
