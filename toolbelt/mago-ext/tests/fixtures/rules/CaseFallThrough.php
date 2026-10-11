<?php

declare(strict_types = 1);

namespace Acme\Widget;

function route(int $kind, array $items): int {
  $total = 0;
  switch ($kind) {
    case 1:
      $total++;
    case 2:
      $total += 2;
      break;
    case 3:
      $total += 3;
      // Falls through: 3 also counts as 4.
    case 4:
    case 5:
      if ($total > 4) {
        return $total;
      }
      else {
        throw new \RuntimeException('small');
      }
    case 6:
      if ($total > 4) {
        return $total;
      }
    case 7:
      foreach ($items as $item) {
        switch ($item) {
          case 'a':
            break 2;
          default:
            continue 2;
        }
      }
    case 8:
      try {
        return 1;
      }
      finally {
        $total = 0;
      }
    case 9:
      {
        exit(1);
      }
    case 10:
      $total = 10;
    default:
      $total = -1;
  }
  return $total;
}

function leaving(int $x, int $y): void {
  // Silent: a parenthesized throw, a throwing match, a nested switch running on into a returning default, a returning finally.
  // Reported: a tool directive is no fall-through note, and an inner break leaves only the inner switch.
  switch ($x) {
    case 1:
      (throw new \RuntimeException('one'));
    case 2:
      match ($y) {
        1 => throw new \RuntimeException('a'),
        default => throw new \RuntimeException('b'),
      };
    case 3:
      switch ($y) {
        case 1:
          $y++;
          // Falls through.
        default:
          return;
      }
    case 4:
      break;
  }
  switch ($x) {
    case 1:
      $y++;
      // phpcs:ignore Generic.Example
    case 2:
      try {
        $y++;
      }
      finally {
        return;
      }
    case 3:
      switch ($y) {
        case 1:
          $y++;
          break;
        default:
          return;
      }
    case 4:
      break;
  }
}
