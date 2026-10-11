<?php

declare(strict_types=1);

// Just enough of core for the banned call sites to resolve.
class CRM_Utils_Array
{
    public static function value(mixed $key, array $list, mixed $default = null): mixed
    {
        return $list[$key] ?? $default;
    }

    public static function first(array $list): mixed
    {
        return $list[array_key_first($list)] ?? null;
    }
}

class CRM_Core_Error
{
    public static function fatal(string $message = ''): void
    {
    }

    public static function debug_log_message(string $message): void
    {
    }

    public static function debug_var(string $name, mixed $value): void
    {
    }

    public static function statusBounce(string $message): void
    {
    }
}

class CRM_Core_Exception_Stub extends CRM_Core_Error
{
}

class PEAR
{
    public static function raiseError(string $message): object
    {
        return new stdClass();
    }
}

function civicrm_api3(string $entity, string $action, array $params = []): array
{
    return [];
}

function civicrm_api(string $entity, string $action, array $params = []): array
{
    return [];
}
