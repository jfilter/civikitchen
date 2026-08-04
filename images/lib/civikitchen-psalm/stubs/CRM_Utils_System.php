<?php

/**
 * Taint SINKS: the CRM_Utils_System calls that end up in a response header.
 *
 * redirect() is a Location header (open redirect / header injection when the
 * URL comes from the request); setHttpHeader() is the raw header API, name
 * and value; download() writes Content-Type from $mimeType and
 * Content-Disposition from $name — a request-controlled filename there is the
 * header-injection half of an upload bug.
 *
 * download()'s $buffer is the response body, i.e. HTML taint, and TaintedHtml
 * is off by policy (see psalm-taint.xml.dist) — deliberately not annotated,
 * so the config stays the single place that decision is made.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Utils/System.php).
 */
class CRM_Utils_System {

  /**
   * @psalm-taint-sink header $url
   */
  public static function redirect($url = NULL, $context = []) {}

  /**
   * @psalm-taint-sink header $name
   * @psalm-taint-sink header $value
   */
  public static function setHttpHeader($name, $value) {}

  /**
   * @psalm-taint-sink header $name
   * @psalm-taint-sink header $mimeType
   * @psalm-taint-sink header $ext
   */
  public static function download(
    $name,
    $mimeType,
    &$buffer,
    $ext = NULL,
    $output = TRUE,
    $disposition = 'attachment'
  ) {}

}
