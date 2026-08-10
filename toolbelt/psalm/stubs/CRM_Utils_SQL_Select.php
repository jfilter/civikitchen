<?php

/**
 * Taint SINKS: the SQL fragments of CRM_Utils_SQL_Select.
 *
 * The builder is the modern way to assemble a query, but every one of these
 * methods takes a raw SQL expression — `where('contact_id = ' . $id)` is an
 * injection the DAO stubs never see, because the string reaches
 * CRM_Core_DAO only via ->execute() much later.
 *
 * $exprs is the sink; $args is NOT — that is the interpolation array
 * (`where('id = #1', [1 => $id])`), the safe path, exactly like the $params
 * of CRM_Core_DAO::executeQuery. param() is the same story.
 *
 * Signatures verified against CiviCRM 6.17.2 (CRM/Utils/SQL/Select.php).
 */
class CRM_Utils_SQL_Select {

  /**
   * @psalm-taint-sink sql $from
   */
  public static function from($from, $options = []): self {}

  /**
   * @psalm-taint-sink sql $from
   */
  public function __construct($from, $options = []) {}

  /**
   * @psalm-taint-sink sql $name
   * @psalm-taint-sink sql $exprs
   */
  public function join($name, $exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function select($exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function where($exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function groupBy($exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function having($exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function orderBy($exprs, $args = NULL, $weight = 0): self {}

  /**
   * @psalm-taint-sink sql $exprs
   */
  public function onDuplicate($exprs, $args = NULL): self {}

  /**
   * @psalm-taint-sink sql $table
   */
  public function insertInto($table, $fields = []): self {}

  /**
   * @psalm-taint-sink sql $table
   */
  public function insertIgnoreInto($table, $fields = []): self {}

  /**
   * @psalm-taint-sink sql $table
   */
  public function replaceInto($table, $fields = []): self {}

  /**
   * @psalm-taint-sink sql $table
   */
  public function syncInto($table, $keys, $mapping, $args = NULL): self {}

  /**
   * Not a sink: the interpolation values, the safe half of the builder.
   */
  public function param($keys, $value = NULL): self {}

  public function limit($limit, $offset = 0): self {}

  public function distinct($isDistinct = TRUE): self {}

  /**
   * The assembled string reaches the database — the DAO stubs cannot see it,
   * because ->execute() does the query itself.
   */
  public function toSQL() {}

  public function execute($daoName = NULL, $i18nRewrite = TRUE) {}

}
