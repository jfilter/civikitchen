<?php

/**
 * Taint SINKS: the CRM_Utils_File helpers that take a filesystem path and act
 * on it — request-controlled input reaching one of these is a path traversal
 * (cleanDir is the one that also deletes).
 *
 * Deliberately a short list: the read-only helpers (isAbsolute, relativize,
 * getExtensionFromPath, …) only inspect a string and are not sinks, and
 * tainting them would flag harmless path arithmetic.
 *
 * runSqlQuery() is not a path sink at all — it hands a raw string to the
 * database, so it is an SQL sink like the DAO methods.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Utils/File.php).
 */
class CRM_Utils_File {

  /**
   * @psalm-taint-sink file $path
   */
  public static function createDir($path, $abort = TRUE) {}

  /**
   * @psalm-taint-sink file $target
   */
  public static function cleanDir(string $target, bool $rmdir = TRUE, bool $verbose = TRUE) {}

  /**
   * @psalm-taint-sink file $source
   * @psalm-taint-sink file $destination
   */
  public static function copyDir($source, $destination) {}

  /**
   * @psalm-taint-sink file $filePath
   */
  public static function duplicate($filePath) {}

  /**
   * @psalm-taint-sink file $fileName
   */
  public static function sourceSQLFile($dsn, $fileName, $prefix = NULL, $dieOnErrors = TRUE) {}

  /**
   * @psalm-taint-sink sql $queryString
   */
  public static function runSqlQuery($dsn, $queryString, $prefix = NULL, $dieOnErrors = TRUE) {}

}
