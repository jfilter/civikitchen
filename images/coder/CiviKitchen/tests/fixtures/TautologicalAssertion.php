<?php

// Fixture: assertions on a bare matching literal must be flagged; assertions
// on real expressions, on the opposite literal, and plain function calls of
// the same name must not.

namespace Fixture;

class TautologicalAssertionTest {

  public function flagged(): void {
    $this->assertTrue(TRUE);
    self::assertTrue(true);
    $this->assertFalse(FALSE);
    $this->assertNotFalse(TRUE);
  }

  public function clean(bool $result, array $rows): void {
    $this->expectNotToPerformAssertions();
    $this->assertTrue($result);
    $this->assertTrue(TRUE === $result);
    $this->assertFalse(isset($rows['missing']));
    $this->assertTrue(FALSE);
    $this->assertSame(TRUE, $result);
    assertTrue(TRUE);
  }

}
