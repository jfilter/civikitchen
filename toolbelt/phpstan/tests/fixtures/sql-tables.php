<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Sql;

final class WidgetQueries
{
    public function reported(): void
    {
        // The bug this rule exists for: a plausible name that no release has.
        \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget_rule WHERE id = 1');
        \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contakt');
        \CRM_Core_DAO::executeUnbufferedQuery(
            'SELECT c.id FROM civicrm_contact c INNER JOIN civicrm_emails e ON e.contact_id = c.id',
        );
        \CRM_Utils_SQL_Select::from('civicrm_gadget g')->execute();
    }

    public function silent(): void
    {
        \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_contact WHERE id = %1');
        \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_value_widget_1');
        \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_tmp_d_abc123');
        // Another extension's table: no civicrm_ prefix, no opinion.
        \CRM_Core_DAO::executeQuery('DELETE FROM widget_rule WHERE id = 1');
        \CRM_Utils_SQL_Select::from('civicrm_contact c')->execute();
        \CRM_Core_DAO::executeQuery(self::sql());
        $table = 'civicrm_contact';
        \CRM_Core_DAO::executeQuery("SELECT id FROM {$table}");
    }

    private static function sql(): string
    {
        return 'SELECT 1 FROM civicrm_nonsense';
    }

    public function builder(): void
    {
        $select = \CRM_Utils_SQL_Select::from('civicrm_contact c');
        $select->join('e', 'LEFT JOIN civicrm_emails e ON e.contact_id = c.id');
        $select->join('a', 'LEFT JOIN civicrm_address a ON a.contact_id = c.id');
        $dao = new \CRM_Core_DAO();
        $dao->query('SELECT id FROM civicrm_widget_rule');
        // Not a query builder at all — no civicrm_ name, nothing to say.
        $this->collection()->join('items');
    }

    private function collection(): self
    {
        return $this;
    }

    public function join(string $what): self
    {
        return $this;
    }

    /** Named arguments bind by name, and class names ignore case. */
    public function namedAndCased(array $args): void
    {
        \CRM_Core_DAO::executeQuery(params: [], query: 'SELECT id FROM civicrm_widget_named');
        \crm_core_dao::executeQuery('SELECT id FROM civicrm_widget_cased');
        \crm_utils_sql_select::from('civicrm_gadget_cased g')->execute();
        $dao = new \CRM_Core_DAO();
        $dao->query(i18nRewrite: false, query: 'SELECT id FROM civicrm_widget_named');
        $select = \CRM_Utils_SQL_Select::from(from: 'civicrm_contact c');
        $select->join(exprs: 'LEFT JOIN civicrm_emails_named e ON e.contact_id = c.id', name: 'e');
        \CRM_Core_DAO::executeQuery(...$args);
    }

    /** Quoted text and comments are not SQL; DROP and RENAME name departing tables. */
    public function literalsCommentsAndDdl(): void
    {
        \CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contact WHERE source = 'imported from civicrm_legacy'");
        \CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_contact WHERE x = "update civicrm_nope"');
        \CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_contact -- FROM civicrm_nope');
        \CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_contact /* from civicrm_x */');
        \CRM_Core_DAO::executeQuery('DROP TABLE civicrm_myext_legacy_log');
        \CRM_Core_DAO::executeQuery('RENAME TABLE civicrm_myext_old TO civicrm_myext_thing');
        \CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_myext_old RENAME TO civicrm_myext_thing');
        \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_myext_thing');
        \CRM_Core_DAO::executeQuery('INSERT civicrm_contcat (x) VALUES (1)');
        \CRM_Core_DAO::executeQuery('TRUNCATE civicrm_contcat');
        \CRM_Core_DAO::executeQuery('SELECT c.id FROM civicrm_contact c, civicrm_emial e WHERE e.contact_id = c.id');
        \CRM_Core_DAO::executeQuery('CREATE TABLE IF NOT EXISTS civicrm_contact_mirror (id INT)');
        \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_contcat (x) VALUES (1)');
        \CRM_Core_DAO::executeQuery('REPLACE INTO civicrm_contcat (x) VALUES (1)');
        \CRM_Core_DAO::executeQuery('TRUNCATE TABLE civicrm_contcat');
        \CRM_Core_DAO::executeQuery('INSERT IGNORE INTO civicrm_contcat (x) VALUES (1)');
        \CRM_Core_DAO::executeQuery("-- remove legacy\nDROP TABLE civicrm_myext_legacy_log");
        \CRM_Core_DAO::executeQuery('/* 1.2 */ RENAME TABLE civicrm_myext_old TO civicrm_myext_thing');
    }
}
