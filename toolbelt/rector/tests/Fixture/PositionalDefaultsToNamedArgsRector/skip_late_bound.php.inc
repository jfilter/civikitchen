<?php

namespace Fixture\LateBound;

interface Renderer {
  public function render(string $page, bool $cache = TRUE, string $suffix = '.html'): string;
}

class Base {
  public function format(string $s, bool $upper = FALSE): string {
    return $upper ? strtoupper($s) : $s;
  }

  public static function make(string $s, int $n = 0, int $m = 0): string {
    return $s;
  }

  public function run(Renderer $r): string {
    return $r->render('page', TRUE, '.htm') . $this->format('x', FALSE) . static::make('a', 0, 5);
  }
}

/**
 * @method string build(string $a, int $b = 0, int $c = 0)
 */
final class Magic {
  public function __call(string $name, array $args): string {
    return implode(',', $args);
  }
}

function counted(int $a, ?int $b = NULL): int {
  return func_num_args();
}

function call(Magic $m): void {
  $m->build('a', 0, 5);
  counted(1, NULL);
  (new \DateTime())->setTime(1, 2, 0, 5);
}
