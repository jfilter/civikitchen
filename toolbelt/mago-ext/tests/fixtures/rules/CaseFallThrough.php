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
