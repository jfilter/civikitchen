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
 * Signatures verified against CiviCRM 6.17.2 (CRM/Utils/File.php).
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

  /**
   * @psalm-taint-sink file $dir
   */
  public static function findFiles($dir, $pattern, $relative = FALSE, ?int $maxDepth = NULL) {}

  /**
   * @psalm-taint-sink file $path
   */
  public static function getFilesByExtension($path, $ext) {}

  /**
   * @psalm-taint-sink file $fromDir
   * @psalm-taint-sink file $toDir
   */
  public static function replaceDir($fromDir, $toDir, $verbose = FALSE) {}

  /**
   * @psalm-taint-sink file $dir
   * @psalm-taint-sink file $fileName
   */
  public static function createFakeFile($dir, $contents = 'delete me', $fileName = NULL) {}

  /**
   * @psalm-taint-sink file $sourceFile
   */
  public static function resizeImage($sourceFile, $targetWidth, $targetHeight, $suffix = "", $preserveAspect = TRUE) {}

  /**
   * ESCAPE: the upload-name sanitizer. It runs the basename through
   * CRM_Utils_String::munge(), appends a random id and forces an unknown
   * extension to `.unknown`, so no separator and no traversal survives.
   * The one right answer for a client-supplied filename.
   *
   * @psalm-taint-escape file
   * @psalm-flow ($name) -> return
   */
  public static function makeFileName($name, bool $unicode = FALSE) {}

  /**
   * @psalm-taint-escape file
   * @psalm-flow ($input) -> return
   */
  public static function makeFilenameWithUnicode(string $input, string $replacementString = '_', int $cutoffLength = 63) {}

  /**
   * NOT an escape: it only strips the md5 CiviCRM added to a stored file name
   * and returns the rest unchanged. Stubbed so it stays a pass-through
   * instead of laundering taint through an empty stub body.
   *
   * @psalm-flow ($name) -> return
   */
  public static function cleanFileName($name) {}

}
