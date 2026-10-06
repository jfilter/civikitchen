<?php

namespace Fixture\Bound;

final class Greeter {
  public function greet(string $name, string $greeting = 'Hello', string $suffix = '!'): string {
    return $greeting . $name . $suffix;
  }
}

class Helper {
  public static function pad(string $s, int $left = 0, int $right = 0): string {
    return str_repeat(' ', $left) . $s . str_repeat(' ', $right);
  }

  public function run(): string {
    return self::pad('a', 0, 3) . $this->own('a', NULL, 2);
  }

  private function own(string $s, ?string $prefix = NULL, int $times = 1): string {
    return str_repeat($prefix . $s, $times);
  }
}

function opt(int $a, ?int $b = NULL, int $c = 0): int {
  return $a + $c;
}

function call(Greeter $g): void {
  $g->greet('x', 'Hello', '?');
  opt(1, NULL, 5);
}

?>
-----
<?php

namespace Fixture\Bound;

final class Greeter {
  public function greet(string $name, string $greeting = 'Hello', string $suffix = '!'): string {
    return $greeting . $name . $suffix;
  }
}

class Helper {
  public static function pad(string $s, int $left = 0, int $right = 0): string {
    return str_repeat(' ', $left) . $s . str_repeat(' ', $right);
  }

  public function run(): string {
    return self::pad('a', right: 3) . $this->own('a', times: 2);
  }

  private function own(string $s, ?string $prefix = NULL, int $times = 1): string {
    return str_repeat($prefix . $s, $times);
  }
}

function opt(int $a, ?int $b = NULL, int $c = 0): int {
  return $a + $c;
}

function call(Greeter $g): void {
  $g->greet('x', suffix: '?');
  opt(1, c: 5);
}

?>
