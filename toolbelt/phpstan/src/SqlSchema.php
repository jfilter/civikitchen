<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Table names in raw SQL, judged against the schema.
 *
 * Raw SQL is the one place in an extension where a name is never checked
 * until the statement runs: `DELETE FROM civicrm_civirules_rule` (the table
 * is called civirule_rule) is a clean parse, a clean phpstan run, a clean
 * unit test — and a fatal on a customer's site. The catalog makes the table
 * half of that checkable.
 *
 * The calibration is deliberately narrow, because the schema of a live site
 * is core plus whatever extensions are installed, and the analysis knows
 * only core plus this repo:
 *
 *  - only names starting with `civicrm_` are judged at all. That prefix is
 *    core's; an extension table called `civirule_rule` or `mailjet_event`
 *    is invisible to us and stays silent.
 *  - `civicrm_value_*` (custom groups), `civicrm_tmp_*` / `civicrm_temp*`
 *    (CRM_Utils_SQL_TempTable) and anything the repo's own schema declares
 *    are known by construction.
 *  - other extensions DO ship `civicrm_`-prefixed tables. A repo that
 *    queries one lists it in phpstan.neon under
 *    `parameters.civikitchen.sqlKnownTables`.
 *  - only literal SQL is read. A table name that arrives through a
 *    variable, `{$table}` or a `%1` placeholder is skipped without a word.
 */
final class SqlSchema
{
    /**
     * Table families that exist per site, not per release.
     *
     * Custom-value tables are named after a custom group, temp tables after
     * a random suffix; both are `civicrm_`-prefixed and neither can be in
     * any catalog.
     */
    private const DYNAMIC_PREFIXES = ['civicrm_value_', 'civicrm_tmp_', 'civicrm_temp'];

    /** SQL keywords a table name follows; INSERT's INTO and TRUNCATE's TABLE are optional. */
    private const TABLE_KEYWORDS = 'FROM|JOIN|INTO|UPDATE|TABLE|(?:INSERT|REPLACE)(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED|HIGH_PRIORITY))*(?:\s+INTO)?|TRUNCATE(?:\s+TABLE)?';

    /** String literals and comments, whose words are not SQL. */
    private const LITERALS_AND_COMMENTS = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|--[^\n]*|#[^\n]*|\/\*.*?\*\//s';

    /** Where the table list of a FROM clause ends. */
    private const FROM_LIST_END = 'WHERE|GROUP|ORDER|HAVING|LIMIT|UNION|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|ON|USING|SET|FOR|LOCK|INTO';

    private string $extensionDir;

    /** @var list<string> */
    private array $configuredTables;

    /** @var list<string>|null */
    private ?array $ownTables = null;

    /**
     * @param list<string> $knownTables tables the repo declares in phpstan.neon
     */
    public function __construct(string $currentWorkingDirectory, array $knownTables = [])
    {
        $this->extensionDir = $currentWorkingDirectory;
        $this->configuredTables = array_map('strtolower', $knownTables);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkSql(string $sql, string $context): array
    {
        $errors = [];
        foreach (self::tablesIn($sql) as $table) {
            if ($this->isKnown($table)) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'SQL table %s does not exist in CiviCRM %s — %s',
                $table,
                SchemaCatalog::CORE_VERSION,
                $context,
            ))->identifier('ck.sql.unknownTable')->build();
        }

        return $errors;
    }

    /**
     * A `from()`/`join()` argument: `civicrm_contact c` or `civicrm_email e`.
     *
     * @return list<IdentifierRuleError>
     */
    public function checkTableClause(string $clause, string $context): array
    {
        $name = strtok(trim($clause), " \t\n");
        if ($name === false) {
            return [];
        }
        $name = trim($name, '`');
        if (!self::isPlainName($name) || $this->isKnown($name)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'SQL table %s does not exist in CiviCRM %s — %s',
                $name,
                SchemaCatalog::CORE_VERSION,
                $context,
            ))->identifier('ck.sql.unknownTable')->build(),
        ];
    }

    /**
     * The `civicrm_`-prefixed table names a literal statement mentions.
     *
     * @return list<string>
     */
    public static function tablesIn(string $sql): array
    {
        $names = [];
        // A literal becomes an empty one, a comment whitespace, so a leading comment keeps `^DROP` intact.
        $stripped = (string) preg_replace_callback(
            self::LITERALS_AND_COMMENTS,
            static fn (array $m): string => in_array($m[0][0], ["'", '"'], true) ? "''" : ' ',
            $sql,
        );
        foreach (explode(';', $stripped) as $statement) {
            // A DROP or RENAME names a table that is about to stop existing.
            if (preg_match('/^\s*(DROP|RENAME)\b|^\s*ALTER\s+TABLE\b.*\bRENAME\b/is', $statement) === 1) {
                continue;
            }
            preg_match_all('/\b(?:' . self::TABLE_KEYWORDS . ')\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_{}$%.\\\\]+)`?/i', $statement, $matches);
            $names = array_merge($names, $matches[1]);
            // `FROM a x, b y`: every item of the list, not just the first.
            preg_match_all('/\bFROM\s+(.*?)(?=\b(?:' . self::FROM_LIST_END . ')\b|\)|$)/is', $statement, $lists);
            foreach ($lists[1] as $list) {
                foreach (array_slice(explode(',', $list), 1) as $item) {
                    $names[] = trim((string) strtok(trim($item), " \t\n"), '`');
                }
            }
        }

        return array_values(array_unique(array_filter($names, self::isPlainName(...))));
    }

    /**
     * Is this a name we may have an opinion about?
     *
     * Anything carrying interpolation (`{$table}`, `%1`), a database
     * qualifier (`otherdb.civicrm_contact`) or a prefix that is not core's
     * is somebody else's business.
     */
    private static function isPlainName(string $name): bool
    {
        return preg_match('/^civicrm_[a-z0-9_]+$/i', $name) === 1;
    }

    private function isKnown(string $table): bool
    {
        $table = strtolower($table);
        if (in_array($table, SchemaCatalog::TABLES, true)) {
            return true;
        }
        foreach (self::DYNAMIC_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return in_array($table, $this->configuredTables, true)
            || in_array($table, $this->ownTables(), true);
    }

    /**
     * Tables the analysed extension installs itself.
     *
     * Three declaration styles are read, because all three are in the field:
     * the modern schema/*.entityType.php, civix's xml/schema/**\/*.xml, and
     * the generated sql/auto_install.sql.
     *
     * @return list<string>
     */
    private function ownTables(): array
    {
        if ($this->ownTables !== null) {
            return $this->ownTables;
        }

        $tables = [];
        foreach (self::globRecursive($this->extensionDir . '/schema', '.entityType.php') as $file) {
            if (preg_match("/['\"]table['\"]\\s*=>\\s*['\"]([A-Za-z0-9_]+)['\"]/", (string) file_get_contents($file), $m) === 1) {
                $tables[] = $m[1];
            }
        }
        foreach (self::globRecursive($this->extensionDir . '/xml/schema', '.xml') as $file) {
            if (preg_match('#<name>\s*([A-Za-z0-9_]+)\s*</name>#', (string) file_get_contents($file), $m) === 1) {
                $tables[] = $m[1];
            }
        }
        foreach (self::globRecursive($this->extensionDir . '/sql', '.sql') as $file) {
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', (string) file_get_contents($file), $m) > 0) {
                $tables = array_merge($tables, $m[1]);
            }
        }

        return $this->ownTables = array_values(array_unique(array_map('strtolower', $tables)));
    }

    /**
     * @return list<string>
     */
    private static function globRecursive(string $dir, string $suffix): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), $suffix)) {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }
}
