<?php

declare(strict_types = 1);

namespace Acme\Widget;

function client(): ?string {
  $address = $_SERVER['REMOTE_ADDR'] ?? NULL;
  log($_SERVER["REMOTE_ADDR"]);
  $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
  ['k' => $x] = [$_SERVER['REMOTE_ADDR'] => 1];
  // Silent: writes that seed a request, other keys, other arrays.
  $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
  unset($_SERVER['REMOTE_ADDR']);
  [$_SERVER['REMOTE_ADDR']] = ['192.0.2.2'];
  $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $row = ['REMOTE_ADDR' => 1];
  return $row['REMOTE_ADDR'] . $agent . $address . $x;
}

function log(?string $value): void {
}
