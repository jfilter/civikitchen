<?php

/**
 * ESCAPE — and one deliberate non-escape.
 *
 * escape() is CiviCRM's real sanitizer: the scalar types come back cast
 * (int/float/validated date), and 'String'/'Memo'/'Text' come back through
 * CRM_Core_DAO::escapeString(). SQL-safe, so `psalm-taint-escape` sql.
 *
 * validate() is NOT an escape and is stubbed here to say so: for
 * 'String'/'Text'/'Memo'/'Link' it returns $data completely unchanged
 * (CRM/Utils/Type.php, verified in 6.16.2). Left unstubbed it would still
 * launder taint — a stub without a body returns something Psalm considers
 * clean — so it carries an explicit `psalm-flow` instead and stays a
 * pass-through in the graph.
 *
 * Both keep the flow so that a value escaped for SQL is still tainted for
 * shell/html/file sinks.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Utils/Type.php).
 */
class CRM_Utils_Type {

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($data) -> return
   */
  public static function escape($data, $type, $abort = TRUE) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($data) -> return
   */
  public static function escapeAll($data, $type, $abort = TRUE) {}

  /**
   * @psalm-flow ($data) -> return
   */
  public static function validate($data, $type, $abort = TRUE, $name = 'One of parameters ') {}

  /**
   * @psalm-flow ($data) -> return
   */
  public static function validateAll($data, $type) {}

}
