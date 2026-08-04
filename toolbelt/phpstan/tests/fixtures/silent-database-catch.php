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

    /** APIv4 exceptions are control flow, not swallowed database errors. */
    public function api4(): array
    {
        try {
            return Contact::get(false)->addSelect('display_name')->execute();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
