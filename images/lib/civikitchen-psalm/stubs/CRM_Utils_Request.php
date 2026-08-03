<?php

/**
 * Taint SOURCES: the CiviCRM way of reading request input.
 *
 * $_GET/$_POST/$_REQUEST/$_COOKIE are already sources in Psalm itself; almost
 * no extension touches them directly, it goes through CRM_Utils_Request.
 *
 * retrieve() does run the value through CRM_Utils_Type::validate($type), so a
 * 'Positive'/'Integer' retrieval is in fact safe — but the type arrives as a
 * runtime string, and Psalm cannot make a taint decision per argument value.
 * Tainting unconditionally is the honest choice: it over-reports the typed
 * retrievals rather than under-reporting the 'String' ones, which are the
 * dangerous majority. See docs/extension-standards.md for how to dismiss one.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Utils/Request.php).
 */
class CRM_Utils_Request {

  /**
   * @psalm-taint-source input
   */
  public static function retrieve($name, $type, $store = NULL, $abort = FALSE, $default = NULL, $method = 'REQUEST') {}

  /**
   * @psalm-taint-source input
   */
  public static function retrieveValue($name, $type, $defaultValue = NULL, $isRequired = FALSE, $method = 'REQUEST') {}

}
