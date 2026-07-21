<?php

// Fixture: single-argument unserialize() must be flagged; any call that passes
// an explicit $options array must not, and neither may near-misses that only
// look like the function call.

namespace Fixture;

class UnsafeUnserialize {

  public function flagged(string $raw): array {
    $params = unserialize($raw);
    $nested = unserialize(trim($raw, ' '));
    $upper = UNSERIALIZE($raw);
    return [$params, $nested, $upper];
  }

  public function clean(string $raw, Serializer $service): array {
    $safe = unserialize($raw, ['allowed_classes' => FALSE]);
    $listed = unserialize($raw, ['allowed_classes' => [self::class]]);
    $method = $service->unserialize($raw);
    $static = Serializer::unserialize($raw);
    return [$safe, $listed, $method, $static];
  }

  public function unserialize(string $raw): array {
    return (array) $raw;
  }

}
