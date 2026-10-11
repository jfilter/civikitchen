<?php

declare(strict_types = 1);

namespace Acme\Widget;

function fetch(\CurlHandle $handle, bool $verify): void {
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, FALSE);
  \curl_setopt($handle, \CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, (0x0));
  curl_setopt(value: !TRUE, handle: $handle, option: CURLOPT_SSL_VERIFYPEER);
  curl_setopt_array($handle, [CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYHOST => FALSE]);
  // Silent: verification stays on, or the value is not a literal.
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, TRUE);
  curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $verify);
  curl_setopt($handle, CURLOPT_TIMEOUT, 0);
  curl_setopt_array($handle, [CURLOPT_SSL_VERIFYPEER => TRUE, CURLOPT_FOLLOWLOCATION => FALSE]);
  Acme\curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, FALSE);
}

function parenthesized(\CurlHandle $handle): void {
  curl_setopt($handle, (CURLOPT_SSL_VERIFYPEER), false);
  curl_setopt_array($handle, ([CURLOPT_SSL_VERIFYPEER => false]));
  // A later duplicate key decides: silent, then reported on its second entry.
  curl_setopt_array($handle, [CURLOPT_SSL_VERIFYPEER => FALSE, CURLOPT_SSL_VERIFYPEER => TRUE]);
  curl_setopt_array($handle, [
    CURLOPT_SSL_VERIFYPEER => TRUE,
    CURLOPT_SSL_VERIFYPEER => FALSE,
  ]);
}
