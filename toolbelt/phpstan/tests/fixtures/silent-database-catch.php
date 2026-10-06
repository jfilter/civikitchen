<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\SilentCatch;

use Civi\Api4\Contact;

final class WidgetReport
{
    /** The failure disappears and the caller reads it as "no widgets". */
    public function silent(): int
    {
        try {
            return (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_widget');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function empty(): void
    {
        try {
            \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Throwable $e) {
        }
    }

    /** debug() is off wherever it would matter. */
    public function debugOnly(): int
    {
        try {
            return (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_widget');
        } catch (\Throwable $e) {
            \Civi::log()->debug('no widgets', ['exception' => $e]);

            return 0;
        }
    }

    public function logsLoudly(): int
    {
        try {
            return (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_widget');
        } catch (\Throwable $e) {
            \Civi::log()->error('widget count failed', ['exception' => $e]);

            return 0;
        }
    }

    public function rethrows(): int
    {
        try {
            return (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_widget');
        } catch (\Throwable $e) {
            throw new \RuntimeException('widget count failed', 0, $e);
        }
    }

    public function cased(): void
    {
        try {
            \crm_core_dao::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Throwable $e) {
        }
    }

    /** APIv4 exceptions are control flow, not swallowed database errors. */
    public function api4(): array
    {
        try {
            return Contact::get(false)->addSelect('display_name')->execute();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function genericLog(): void
    {
        try {
            \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Exception $e) {
            \Civi::log()->log('error', 'x');
        }
        try {
            \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Exception $e) {
            \Civi::log()->log(\Psr\Log\LogLevel::ERROR, 'x');
        }
        try {
            \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Exception $e) {
            \Civi::log()?->error('x');
        }
    }

    /** log() below error level is as quiet as debug(). */
    public function genericLogQuiet(): void
    {
        try {
            \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget');
        } catch (\Exception $e) {
            \Civi::log()->log(\Psr\Log\LogLevel::INFO, 'x');
        }
    }

    /** CRM_Utils_SQL_Select::execute() runs CRM_Core_DAO::executeQuery(). */
    public function sqlBuilder(): void
    {
        try {
            \CRM_Utils_SQL_Select::from('civicrm_contact')->execute();
        } catch (\Exception $e) {
        }
        $select = \CRM_Utils_SQL_Select::from('civicrm_contact');
        try {
            $select->execute();
        } catch (\Exception $e) {
        }
        $report = new \CiviKitchen\Fixtures\Support\ReportBuilder();
        try {
            $report->execute();
        } catch (\Exception $e) {
        }
    }
}
