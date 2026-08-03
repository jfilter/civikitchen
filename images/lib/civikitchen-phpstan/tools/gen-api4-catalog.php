<?php

declare(strict_types=1);

/**
 * Regenerate src/Api4Catalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-api4-catalog.php <core-dir> [out-file]
 *
 * Reads the SOURCE tree only — no booted CiviCRM, no database. Everything
 * comes out of the tokenizer, so the catalog can be built in CI from a
 * tarball and pinned to a release.
 *
 * Entities are the top-level classes in Civi/Api4/ of core and of every
 * core-shipped extension. Actions are the static factory methods on the
 * entity and its base classes (DAOEntity, BasicEntity, AbstractEntity)
 * plus every class under Civi/Api4/Action/<Entity>/, which AbstractEntity
 * exposes through __callStatic. Fields come from schema/*.entityType.php.
 *
 * What is NOT knowable statically, and is therefore marked incomplete:
 * custom fields, ECK types, multi-record custom groups, SearchKit's SK_*
 * entities and everything a spec provider adds behind a runtime condition.
 * Entities without an entityType definition get fields_complete = false and
 * the rule then checks their actions but never their field names.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-api4-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

if (!is_file($coreDir . '/Civi.php') || !is_dir($coreDir . '/Civi/Api4')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir has no Civi.php/Civi/Api4\n");
    exit(66);
}

/**
 * Static methods that exist on entity classes but are not API actions.
 *
 * The general test is the action-factory shape (`new SomethingAction(...,
 * __FUNCTION__)`); these are the reflection helpers that share the static
 * modifier without returning an action.
 */
const NOT_ACTIONS = ['getEntityName', 'getEntityTitle', 'getInfo', 'getDaoName', 'permissions'];

/**
 * Entity name prefixes whose entities only exist once a site is configured.
 *
 * `Custom_Foo` follows a multi-record custom group, `Eck_Foo` an entity
 * construction kit type, `SK_Foo` a SearchKit search. No source tree can
 * enumerate them, so the rule treats the prefix as "assume it exists".
 */
const DYNAMIC_PREFIXES = ['Custom_', 'Eck_', 'SK_', 'Import_'];

/** Classloader roots: core itself plus every ext dir carrying an info.xml. */
function classloaderRoots(string $coreDir): array
{
    $roots = [$coreDir];
    if (!is_dir($coreDir . '/ext')) {
        return $roots;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($coreDir . '/ext', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $it->setMaxDepth(2);
    foreach ($it as $entry) {
        if ($entry->isFile() && $entry->getFilename() === 'info.xml') {
            $roots[] = $entry->getPath();
        }
    }

    return $roots;
}

/** Tokens without whitespace and comments, reindexed. */
function significantTokens(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $tokens[] = $token;
    }

    return array_values($tokens);
}

function tokenIs(array $tokens, int $i, $type): bool
{
    $token = $tokens[$i] ?? null;
    if ($token === null) {
        return false;
    }

    return is_string($type) ? $token === $type : (is_array($token) && $token[0] === $type);
}

function tokenText(array $tokens, int $i): string
{
    $token = $tokens[$i] ?? '';

    return is_array($token) ? $token[1] : $token;
}

/**
 * The class declared in a PHP source, as [name, parent, isAbstract].
 */
function classDeclaration(string $source): ?array
{
    $tokens = significantTokens($source);
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }
        // `::class` and anonymous classes are not declarations.
        if (tokenIs($tokens, $i - 1, T_DOUBLE_COLON) || tokenIs($tokens, $i + 1, '(')) {
            continue;
        }
        $abstract = tokenIs($tokens, $i - 1, T_ABSTRACT)
            || tokenIs($tokens, $i - 2, T_ABSTRACT);
        $name = tokenText($tokens, $i + 1);
        $parent = null;
        if (tokenIs($tokens, $i + 2, T_EXTENDS)) {
            $parent = ltrim(tokenText($tokens, $i + 3), '\\');
        }

        return [$name, $parent, $abstract];
    }

    return null;
}

/**
 * Public static methods of a class, as name => body source.
 *
 * Abstract methods (no body) map to an empty string — AbstractEntity
 * declares getFields that way and it is still an action.
 */
function staticMethods(string $source, bool $staticOnly = true): array
{
    $tokens = significantTokens($source);
    $methods = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!tokenIs($tokens, $i, T_FUNCTION)) {
            continue;
        }
        $isStatic = false;
        for ($back = $i - 1; $back >= 0 && $back >= $i - 4; $back--) {
            if (tokenIs($tokens, $back, T_STATIC)) {
                $isStatic = true;
            } elseif (!is_array($tokens[$back]) || !in_array($tokens[$back][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL], true)) {
                break;
            }
        }
        if ($staticOnly && !$isStatic) {
            continue;
        }
        $name = tokenText($tokens, $i + 1);
        // Walk to the body, or to the `;` of an abstract declaration.
        $body = '';
        for ($j = $i + 2; $j < $count; $j++) {
            if (tokenIs($tokens, $j, ';')) {
                break;
            }
            if (tokenIs($tokens, $j, '{')) {
                $depth = 0;
                for ($k = $j; $k < $count; $k++) {
                    if (tokenIs($tokens, $k, '{') || tokenIs($tokens, $k, T_CURLY_OPEN) || tokenIs($tokens, $k, T_DOLLAR_OPEN_CURLY_BRACES)) {
                        $depth++;
                    } elseif (tokenIs($tokens, $k, '}')) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $body .= tokenText($tokens, $k);
                }
                break;
            }
        }
        $methods[$name] = $body;
    }

    return $methods;
}

/** Does this static method body build and return an API action object? */
function isActionFactory(string $name, string $body): bool
{
    if (in_array($name, NOT_ACTIONS, true)) {
        return false;
    }
    if ($body === '') {
        // Abstract declaration on a base class: getFields and friends.
        return true;
    }

    return str_contains($body, '__FUNCTION__')
        || preg_match('/new\s*\\\\?(\w+\\\\)*\w*Action\s*\(/', $body) === 1;
}

/**
 * Field names in an entityType definition: the keys of the getFields array.
 *
 * Parsed rather than executed — the closures call ts() and reach into
 * CRM_Core_DAO, which needs a booted core. Depth-1 keys of the array literal
 * are the field names; nested arrays (usage lists, pseudoconstants) sit
 * deeper and are skipped by the bracket count.
 *
 * @return list<string>
 */
function entityTypeFields(string $source): array
{
    $tokens = significantTokens($source);
    $count = count($tokens);
    $fields = [];

    for ($i = 0; $i < $count; $i++) {
        if (!tokenIs($tokens, $i, T_CONSTANT_ENCAPSED_STRING) || trim(tokenText($tokens, $i), "'\"") !== 'getFields') {
            continue;
        }
        // 'getFields' => fn() => [
        $open = null;
        for ($j = $i + 1; $j < min($i + 12, $count); $j++) {
            if (tokenIs($tokens, $j, '[')) {
                $open = $j;
                break;
            }
        }
        if ($open === null) {
            continue;
        }
        $depth = 1;
        for ($j = $open + 1; $j < $count && $depth > 0; $j++) {
            if (tokenIs($tokens, $j, '[')) {
                $depth++;
                continue;
            }
            if (tokenIs($tokens, $j, ']')) {
                $depth--;
                continue;
            }
            if ($depth === 1 && tokenIs($tokens, $j, T_CONSTANT_ENCAPSED_STRING) && tokenIs($tokens, $j + 1, T_DOUBLE_ARROW)) {
                $fields[] = trim(tokenText($tokens, $j), "'\"");
            }
        }
        break;
    }

    return $fields;
}

/** The entity name a class declares, when it differs from the class name. */
function declaredEntityName(array $methods, string $fallback): string
{
    $body = $methods['getEntityName'] ?? '';
    if ($body !== '' && preg_match('/return\s*(\'[^\']+\'|"[^"]+")/', $body, $m) === 1) {
        return trim($m[1], '\'"');
    }

    return $fallback;
}

// ---------------------------------------------------------------------------
// Collect classes: entities plus the Generic base classes they extend.
// ---------------------------------------------------------------------------

$roots = classloaderRoots($coreDir);

/** @var array<string, array{parent: ?string, abstract: bool, actions: list<string>, entity: string, isEntity: bool}> */
$classes = [];
/** @var array<string, list<string>> class name => actions from Action/<Class>/ */
$magicActions = [];

foreach ($roots as $root) {
    foreach (array_merge(
        glob($root . '/Civi/Api4/*.php') ?: [],
        glob($root . '/Civi/Api4/Generic/*.php') ?: [],
    ) as $file) {
        $source = (string) file_get_contents($file);
        $declaration = classDeclaration($source);
        if ($declaration === null) {
            continue;
        }
        [$name, $parent, $abstract] = $declaration;
        $methods = staticMethods($source);
        $actions = [];
        foreach ($methods as $method => $body) {
            if (isActionFactory($method, $body)) {
                $actions[] = $method;
            }
        }
        $classes[$name] = [
            'parent' => $parent === null ? null : preg_replace('/^Generic\\\\/', '', $parent),
            'abstract' => $abstract,
            'actions' => $actions,
            'entity' => declaredEntityName($methods, $name),
            'isEntity' => str_ends_with(dirname($file), '/Civi/Api4'),
        ];
    }

    foreach (glob($root . '/Civi/Api4/Action/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $class = basename($dir);
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            // Abstract bases and traits share the directory with the actions
            // but are not callable — AbstractProcessor is not an action.
            $declaration = classDeclaration((string) file_get_contents($file));
            if ($declaration === null || $declaration[2]) {
                continue;
            }
            $magicActions[$class][] = lcfirst(basename($file, '.php'));
        }
    }
}

// ---------------------------------------------------------------------------
// Collect fields from the entityType definitions.
// ---------------------------------------------------------------------------

/** @var array<string, list<string>> entity name => field names */
$entityTypeFields = [];

foreach ($roots as $root) {
    $schemaDir = $root . '/schema';
    if (!is_dir($schemaDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($schemaDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.entityType.php')) {
            continue;
        }
        $source = (string) file_get_contents($entry->getPathname());
        if (preg_match('/[\'"]name[\'"]\s*=>\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $source, $m) !== 1) {
            continue;
        }
        $fields = entityTypeFields($source);
        if ($fields !== []) {
            $entityTypeFields[$m[1]] = $fields;
        }
    }
}

// ---------------------------------------------------------------------------
// Extra fields contributed by spec providers.
//
// Spec providers add fields at runtime that no schema file mentions —
// Contact's `groups` and `age_years`, Activity's `source_contact_id`. Only
// the literal `new FieldSpec('x', ...)` names are collectable; the entity is
// taken from every entity name the provider mentions, plus its class name.
// Over-attribution is deliberate: an extra field name can only silence an
// error, never invent one.
// ---------------------------------------------------------------------------

/** @var array<string, list<string>> */
$specFields = [];
/** @var list<string> fields from providers with no identifiable entity */
$anyEntityFields = [];

$entityNames = [];
foreach ($classes as $name => $class) {
    if ($class['isEntity']) {
        $entityNames[$class['entity']] = true;
    }
}

foreach ($roots as $root) {
    $providerDir = $root . '/Civi/Api4/Service/Spec/Provider';
    if (!is_dir($providerDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($providerDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents($entry->getPathname());
        $methods = staticMethods($source, false);
        preg_match_all('/new\s+FieldSpec\s*\(\s*\'([a-z][A-Za-z0-9_]*)\'/', $source, $m);
        $fields = array_values(array_unique($m[1]));
        if ($fields === []) {
            continue;
        }

        $targets = [];
        $className = basename($entry->getFilename(), '.php');
        $stem = preg_replace('/(Creation|Get|Filter)?SpecProvider$/', '', $className);
        if (isset($entityNames[$stem])) {
            $targets[$stem] = true;
        }
        // The entity the provider declares it applies to, and the one an
        // explicit `new FieldSpec('x', 'Foo')` names. Every other entity
        // literal in the file is a reference (a foreign key, a join) and
        // attributing the field to it would blur the catalog.
        preg_match_all('/\'([A-Z][A-Za-z0-9_]*)\'/', $methods['applies'] ?? '', $applies);
        preg_match_all('/new\s+FieldSpec\s*\(\s*\'[^\']+\'\s*,\s*\'([A-Z][A-Za-z0-9_]*)\'/', $source, $explicit);
        foreach (array_merge($applies[1], $explicit[1]) as $literal) {
            if (isset($entityNames[$literal])) {
                $targets[$literal] = true;
            }
        }

        if ($targets === []) {
            $anyEntityFields = array_merge($anyEntityFields, $fields);
            continue;
        }
        foreach (array_keys($targets) as $target) {
            $specFields[$target] = array_merge($specFields[$target] ?? [], $fields);
        }
    }
}

// ---------------------------------------------------------------------------
// Fold class hierarchy into the entity catalog.
// ---------------------------------------------------------------------------

/** Actions of a class including everything it inherits. */
function inheritedActions(string $class, array $classes, array $magicActions, array $seen = []): array
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return [];
    }
    $seen[$class] = true;
    $actions = array_merge(
        $classes[$class]['actions'],
        $magicActions[$class] ?? [],
    );
    $parent = $classes[$class]['parent'];
    if ($parent !== null) {
        $actions = array_merge($actions, inheritedActions($parent, $classes, $magicActions, $seen));
    }

    return $actions;
}

/** Does the class descend from DAOEntity — i.e. is it backed by a table? */
function isDaoEntity(string $class, array $classes, array $seen = []): bool
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return false;
    }
    $seen[$class] = true;
    if ($classes[$class]['parent'] === 'DAOEntity') {
        return true;
    }

    return $classes[$class]['parent'] !== null && isDaoEntity($classes[$class]['parent'], $classes, $seen);
}

/** The nearest entity in the ancestry that owns an entityType definition. */
function inheritedFieldSource(string $class, array $classes, array $entityTypeFields, array $seen = []): ?string
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return null;
    }
    $seen[$class] = true;
    $entity = $classes[$class]['entity'];
    if ($classes[$class]['isEntity'] && isset($entityTypeFields[$entity])) {
        return $entity;
    }
    $parent = $classes[$class]['parent'];

    return $parent === null ? null : inheritedFieldSource($parent, $classes, $entityTypeFields, $seen);
}

$catalog = [];
$aliases = [];

foreach ($classes as $class => $info) {
    if (!$info['isEntity'] || $info['abstract']) {
        continue;
    }
    $entity = $info['entity'];
    if ($entity !== $class) {
        $aliases[$class] = $entity;
    }

    $actions = array_values(array_unique(inheritedActions($class, $classes, $magicActions)));
    sort($actions);

    $source = inheritedFieldSource($class, $classes, $entityTypeFields);
    $fields = $source === null ? [] : $entityTypeFields[$source];
    $fields = array_merge($fields, $specFields[$entity] ?? []);
    $fields = array_values(array_unique($fields));
    sort($fields);

    // Only a table-backed entity has a field list a schema file can settle.
    // Setting has a civicrm_setting table but its API fields are the setting
    // names, so the DAOEntity ancestry — not the table — is the test.
    $catalog[$entity] = [
        'actions' => $actions,
        'fields' => $fields,
        'complete' => $source !== null && isDaoEntity($class, $classes),
    ];
}

ksort($catalog);
ksort($aliases);
$anyEntityFields = array_values(array_unique($anyEntityFields));
sort($anyEntityFields);

$version = 'unknown';
$versionXml = $coreDir . '/xml/version.xml';
if (is_file($versionXml) && preg_match('#<version_no>([^<]+)</version_no>#', (string) file_get_contents($versionXml), $m)) {
    $version = trim($m[1]);
}

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------

$export = static fn (string $value): string => var_export($value, true);

$entityLines = '';
foreach ($catalog as $entity => $info) {
    $entityLines .= sprintf(
        "        %s => ['a' => %s, 'f' => %s, 'c' => %s],\n",
        $export((string) $entity),
        $export(implode(' ', $info['actions'])),
        $export(implode(' ', $info['fields'])),
        $info['complete'] ? 'true' : 'false',
    );
}

$aliasLines = '';
foreach ($aliases as $class => $entity) {
    $aliasLines .= sprintf("        %s => %s,\n", $export((string) $class), $export($entity));
}

$renderList = static function (array $names) use ($export): string {
    $out = '';
    foreach (array_chunk($names, 6) as $chunk) {
        $out .= '        ' . implode(', ', array_map($export, $chunk)) . ",\n";
    }

    return $out;
};

$prefixes = $renderList(DYNAMIC_PREFIXES);
$anyFields = $renderList($anyEntityFields);

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The APIv4 contract of CiviCRM core — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-api4-catalog.php <core-dir>
 *
 * Per entity: the actions it answers to and the field names a source tree
 * can prove. `c` (complete) says whether the field list may be used to
 * reject an unknown name. It is false wherever the truth only exists on a
 * live site — entities without a schema definition, and everything custom
 * fields, ECK or SearchKit add at runtime. Api4ContractRule checks fields
 * only where `c` is true, and never checks a name containing a dot.
 */
final class Api4Catalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * Entity name => actions, fields, field-list completeness.
     *
     * Space-separated strings rather than nested lists: this is a few
     * thousand names, and one line per entity stays diffable.
     *
     * @var array<string, array{a: string, f: string, c: bool}>
     */
    public const ENTITIES = [
{$entityLines}    ];

    /**
     * Class name => entity name, where the two differ.
     *
     * `\\Civi\\Api4\\CiviCase` is the entity `Case`, because Case is a php
     * keyword. The fluent form uses the class, civicrm_api4() the entity.
     *
     * @var array<string, string>
     */
    public const CLASS_ALIASES = [
{$aliasLines}    ];

    /**
     * Entity name prefixes that only exist on a configured site.
     *
     * @var list<string>
     */
    public const DYNAMIC_PREFIXES = [
{$prefixes}    ];

    /**
     * Fields from spec providers that apply to no single entity.
     *
     * Accepted on every entity: these come from providers whose target is a
     * runtime condition, so the alternative is a false positive.
     *
     * @var list<string>
     */
    public const ANY_ENTITY_FIELDS = [
{$anyFields}    ];

    /** Is this a known entity, or a name only a live site could confirm? */
    public static function knowsEntity(string \$entity): bool
    {
        return isset(self::ENTITIES[\$entity]);
    }

    /** @return list<string> */
    public static function actions(string \$entity): array
    {
        \$actions = self::ENTITIES[\$entity]['a'] ?? '';

        return \$actions === '' ? [] : explode(' ', \$actions);
    }

    /** Whether the field list is exhaustive enough to reject unknown names. */
    public static function hasCompleteFields(string \$entity): bool
    {
        return (self::ENTITIES[\$entity]['c'] ?? false) === true;
    }

    /** @return list<string> */
    public static function fields(string \$entity): array
    {
        \$fields = self::ENTITIES[\$entity]['f'] ?? '';

        return \$fields === '' ? [] : explode(' ', \$fields);
    }
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/Api4Catalog.php';
file_put_contents($target, $out);

$complete = count(array_filter($catalog, static fn (array $i): bool => $i['complete']));
$fieldCount = array_sum(array_map(static fn (array $i): int => count($i['fields']), $catalog));

fwrite(STDOUT, sprintf(
    "%s: %d entities (%d with a complete field list), %d fields, %d aliases (CiviCRM %s)\n",
    $target,
    count($catalog),
    $complete,
    $fieldCount,
    count($aliases),
    $version,
));
