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
}
