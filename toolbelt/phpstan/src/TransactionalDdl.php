<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * What counts as schema work inside a transactional test, and how it is
 * reported. Shared by the analyser rules that look for it.
 */
final class TransactionalDdl
{
    public const TRANSACTIONAL_INTERFACE = 'Civi\\Test\\TransactionalInterface';

    /** APIv4 and APIv3 actions that run DDL on each entity above. */
    public const WRITE_ACTIONS = [
        'CustomField' => ['create', 'save', 'update', 'delete', 'replace', 'setvalue'],
        // An update alters the table only when it flips is_multiple.
        'CustomGroup' => ['create', 'save', 'delete', 'replace'],
    ];

    /** BAO entry points that create, alter or drop the custom-value table. */
    public const DDL_BAO_METHODS = [
        'create', 'writerecord', 'writerecords', 'deleterecord', 'deleterecords',
        'createtable', 'createfield', 'deletefield', 'deletegroup',
    ];

    public const EXTENSION_ACTIONS = ['install', 'enable', 'disable', 'uninstall'];

    private const ADVICE = 'MySQL commits implicitly on DDL, which ends the test transaction and leaves the rows behind — move it to setUpHeadless().';

    private function __construct()
    {
    }

    /**
     * The canonical entity name when $action on $entity runs DDL, else null.
     * Names resolve case-insensitively, and APIv3 also takes `custom_field`.
     */
    public static function ddlEntity(string $entity, string $action): ?string
    {
        $normalised = str_replace('_', '', $entity);
        foreach (self::WRITE_ACTIONS as $ddlEntity => $actions) {
            if (strcasecmp($ddlEntity, $normalised) === 0) {
                return in_array(strtolower($action), $actions, true) ? $ddlEntity : null;
            }
        }

        return null;
    }

    /** `CREATE`, `ALTER`, … when a literal statement would commit the transaction. */
    public static function ddlVerb(string $sql): ?string
    {
        return Sql::isDdlLiteral($sql) ? strtoupper(strtok(ltrim($sql), " \n\t") ?: 'DDL') : null;
    }

    public static function message(string $what): string
    {
        return $what . ' — ' . self::ADVICE;
    }
}
