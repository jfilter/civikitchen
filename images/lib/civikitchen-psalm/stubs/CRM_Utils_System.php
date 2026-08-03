<?php

/**
 * Taint SINK: redirect() ends in a Location header (open redirect / header
 * injection when the URL comes from the request).
 *
 * Signature verified against CiviCRM 6.16.2 (CRM/Utils/System.php).
 */
class CRM_Utils_System {

  /**
   * @psalm-taint-sink header $url
   */
  public static function redirect($url = NULL, $context = []) {}

}
