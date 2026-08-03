<?php

/**
 * Taint SINKS: the query entry points, plus the one real SQL escaper.
 *
 * Only the $query parameter is a sink. The $params array is NOT: that is the
 * safe path (composeQuery type-validates and escapes every placeholder), and
 * tainting it would flag exactly the code we want people to write.
 *
 * escapeString() is a genuine escape, but for SQL only — `psalm-taint-escape`
 * is per taint type, so a value laundered through it still counts as tainted
 * for shell/html/file sinks. That is intended.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Core/DAO.php).
 */
class CRM_Core_DAO {

  /**
   * @psalm-taint-sink sql $query
   */
  public static function &executeQuery(
    $query,
    $params = [],
    $abort = TRUE,
    $daoName = NULL,
    $freeDAO = FALSE,
    $i18nRewrite = TRUE,
    $trapException = FALSE,
    $options = []
  ) {}

  /**
   * @psalm-taint-sink sql $query
   */
  public static function executeUnbufferedQuery(
    $query,
    $params = [],
    $abort = TRUE,
    $daoName = NULL,
    $freeDAO = FALSE,
    $i18nRewrite = TRUE,
    $trapException = FALSE
  ) {}

  /**
   * @psalm-taint-sink sql $query
   */
  public static function &singleValueQuery($query, $params = [], $abort = TRUE, $i18nRewrite = TRUE) {}

  /**
   * composeQuery does not execute anything, but it is where an unparameterized
   * string is assembled — flagging it here catches the injection at the line
   * that builds it, one step before the DAO that runs it.
   *
   * @psalm-taint-sink sql $query
   */
  public static function composeQuery($query, $params = [], $abort = TRUE) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($string) -> return
   */
  public static function escapeString($string) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($strings) -> return
   */
  public static function escapeStrings($strings, $default = NULL) {}

}
