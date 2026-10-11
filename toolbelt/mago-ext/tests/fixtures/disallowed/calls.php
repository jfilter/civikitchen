<?php

declare(strict_types=1);

namespace Acme\Widget;

use CRM_Utils_Array as Arr;

final class Calls
{
    public function banned(array $row): void
    {
        civicrm_api3('Contact', 'get');
        \civicrm_api('Contact', 'get');
        \CRM_Utils_Array::value('id', $row);
        Arr::value('id', $row);
        $class = \CRM_Utils_Array::class;
        $class::value('id', $row);
        \CRM_Core_Error::fatal('gone');
        \CRM_Core_Error::debug_log_message('x');
        \CRM_Core_Error::debug_var('row', $row);
        \PEAR::raiseError('nope');
        \CRM_Core_Exception_Stub::fatal('inherited');
    }

    public function allowed(array $row): void
    {
        \CRM_Utils_Array::first($row);
        \CRM_Core_Error::statusBounce('fine');
        $callable = 'civicrm_api3';
        $this->value('id');
    }

    public function value(string $key): void
    {
    }
}

final class LegacyError extends \CRM_Core_Error
{
    public static function relay(): void
    {
        parent::fatal('via parent');
        self::debug_var('self', 1);
        static::statusBounce('fine');
    }
}

function viaClassString(): void
{
    $sub = \CRM_Core_Exception_Stub::class;
    $sub::fatal('through a subclass name');
}
