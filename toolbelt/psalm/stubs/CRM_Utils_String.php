<?php

/**
 * ESCAPES — the two CRM_Utils_String helpers that really do sanitize, and one
 * that does not.
 *
 * munge() reduces the value to `[a-zA-Z0-9]` plus one replacement character
 * and truncates it, so nothing survives that any of these sinks parses:
 * quotes, semicolons, slashes, dots, CR/LF. Escaping it for sql/shell/file/
 * header/html at once is not generosity, it is what the regex does.
 *
 * purifyHTML() runs the value through the richtext filter (HTMLPurifier) —
 * an HTML escape only, and irrelevant while TaintedHtml is off, but stubbed
 * so it does not silently launder a value into an SQL sink.
 *
 * stripPathChars() is deliberately NOT an escape: it removes quotes and
 * shell metacharacters, but leaves `/`, `.` and `..` untouched, so a path
 * traversal walks straight through it. Left as a pass-through flow.
 *
 * Signatures verified against CiviCRM 6.17.2 (CRM/Utils/String.php).
 */
class CRM_Utils_String {

  /**
   * @psalm-taint-escape sql
   * @psalm-taint-escape shell
   * @psalm-taint-escape file
   * @psalm-taint-escape header
   * @psalm-taint-escape html
   * @psalm-flow ($name) -> return
   */
  public static function munge($name, $char = '_', $len = 63) {}

  /**
   * @psalm-taint-escape html
   * @psalm-flow ($string) -> return
   */
  public static function purifyHTML($string) {}

  /**
   * @psalm-flow ($string) -> return
   */
  public static function stripPathChars($string, $search = NULL, $replace = NULL) {}

}
