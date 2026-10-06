<?php

// Fixture: unserialize() calls that leave allowed_classes at its default
// (flagged); the near misses live in clean() and in CleanModern.php.

namespace Fixture;

class UnsafeUnserialize {

  public function flagged(string $raw, array $rows, array $args, string $k): array {
    $a = unserialize($raw);
    $b = unserialize(trim($raw, ' '));
    $c = UNSERIALIZE($raw);
    $d = \unserialize($raw);
    $e = unserialize(data: $raw);
    $f = unserialize(...);
    $g = unserialize /* reason */ ($raw);
    $h = unserialize($raw,);
    $i = unserialize($raw, []);
    $j = unserialize($raw, ['max_depth' => 5]);
    $l = array_map('unserialize', $rows);
    $m = call_user_func('unserialize', $raw);
    $n = unserialize(match ($k) { 'a', 'b' => $raw, default => $k });
    $o = unserialize(...$args);
    $p = unserialize($raw, array());
    $q = unserialize(data: $raw, options: ['max_depth' => 5]);
    $r = array_map(callback: 'unserialize', array: $rows);
    return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $l, $m, $n, $o, $p, $q, $r];
  }

  public function clean(string $raw, Serializer $service, array $opts, array $rows): array {
    $a = unserialize($raw, ['allowed_classes' => FALSE]);
    $b = unserialize($raw, ['allowed_classes' => [self::class]]);
    $c = unserialize($raw, ['max_depth' => 5, "allowed_classes" => FALSE]);
    $d = unserialize($raw, array('allowed_classes' => FALSE));
    $e = unserialize($raw, $opts);
    $f = unserialize($raw, options: ['allowed_classes' => FALSE]);
    $g = unserialize($raw, [...$opts, 'max_depth' => 5]);
    $h = unserialize($raw, ['max_depth' => 5] + $opts);
    $i = $service->unserialize($raw);
    $j = Serializer::unserialize($raw);
    $k = \Some\Ns\unserialize($raw);
    $l = Ns\unserialize($raw);
    $m = namespace\unserialize($raw);
    $n = array_map('unserialize' . 'Row', $rows);
    $o = in_array('unserialize', $rows, strict: TRUE);
    $p = $service->array_map('unserialize', $rows);
    return [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $p];
  }

  public function unserialize(string $raw): array {
    return (array) $raw;
  }

}
