<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Type;

/**
 * The shared judgement behind the three APIv4 contract rules.
 *
 * An APIv4 call is a string protocol: entity, action and field names are
 * literals that nothing checks until the call runs. A typo in a select
 * survives phpcs, phpstan and every test that does not execute that line,
 * and then throws on a customer's site. The catalog makes the contract
 * checkable at analysis time.
 *
 * The rule only ever speaks when it is sure. Anything non-literal, anything
 * dotted (joins, `custom.*`), anything on an entity whose field list the
 * source tree cannot settle is passed over in silence — a static checker
 * that guesses about a dynamic API teaches people to ignore it.
 */
final class Api4Contract
{
    private const CLAUSE_OPERATORS = ['AND', 'OR', 'NOT'];

    private string $extensionDir;

    private ReflectionProvider $reflectionProvider;

    /** @var list<string>|null */
    private ?array $ownEntities = null;

    public function __construct(string $currentWorkingDirectory, ReflectionProvider $reflectionProvider)
    {
        $this->extensionDir = $currentWorkingDirectory;
        $this->reflectionProvider = $reflectionProvider;
    }

    /**
     * The entity name behind a literal expression, or null to stay silent.
     */
    public function literalString(Expr $expr, Scope $scope): ?string
    {
        return $this->literalStringFromType($scope->getType($expr));
    }

    private function literalStringFromType(Type $type): ?string
    {
        $strings = $type->getConstantStrings();

        return count($strings) === 1 ? $strings[0]->getValue() : null;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkEntity(string $entity, string $context): array
    {
        if ($this->entityIsKnown($entity)) {
            return [];
        }

        // An unknown name is only reportable when it is a near-miss of a core
        // entity. Extensions call each other's entities (CiviRulesRule,
        // Twingle...) and their classes are not in the analysis; the only
        // unknown entity this rule can prove wrong is one that reads like a
        // typo of a real one.
        $suggestion = self::nearestEntity($entity);
        if ($suggestion === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'APIv4 entity %s does not exist in CiviCRM %s — did you mean %s? (%s)',
                $entity,
                Api4Catalog::CORE_VERSION,
                $suggestion,
                $context,
            ))->identifier('ck.api4.unknownEntity')->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkAction(string $entity, string $action, string $context): array
    {
        // Only core's actions are catalogued. An entity from another
        // extension brings its own, unknown, set.
        if (!Api4Catalog::knowsEntity($entity)) {
            return [];
        }
        if (in_array($action, Api4Catalog::actions($entity), true)) {
            return [];
        }
        // Extensions add actions to core entities by dropping a class into
        // Civi\Api4\Action\<Entity>\, which AbstractEntity::__callStatic
        // then resolves.
        if ($this->reflectionProvider->hasClass(sprintf(
            'Civi\\Api4\\Action\\%s\\%s',
            $this->entityClass($entity),
            ucfirst($action),
        ))) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'APIv4 action %s::%s does not exist in CiviCRM %s — %s',
                $entity,
                $action,
                Api4Catalog::CORE_VERSION,
                $context,
            ))->identifier('ck.api4.unknownAction')->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkField(string $entity, string $field, string $clause): array
    {
        if (!Api4Catalog::hasCompleteFields($entity)) {
            return [];
        }
        if (!self::isCheckableFieldName($field)) {
            return [];
        }
        // `status_id:label` selects the option label of a real field.
        [$name] = explode(':', $field, 2);
        if (in_array($name, Api4Catalog::fields($entity), true)) {
            return [];
        }
        if (in_array($name, Api4Catalog::ANY_ENTITY_FIELDS, true)) {
            return [];
        }
        if (self::isWriteClause($clause) && preg_match('/[A-Z]/', $name) === 1) {
            // A write passes its values on to the BAO, which reads control
            // params next to the fields — core itself writes skipStatusCal
            // that way. Field names are snake_case, those params camelCase,
            // and that is the only signal separating them.
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'APIv4 field %s.%s does not exist in CiviCRM %s — %s',
                $entity,
                $name,
                Api4Catalog::CORE_VERSION,
                $clause,
            ))->identifier('ck.api4.unknownField')->build(),
        ];
    }

    /**
     * The right-hand side of a dotted path, against the join's target entity.
     *
     * `address_primary.street_address` is only checkable when the left side
     * is an implicit join the catalog knows: everything else that reads like
     * a path — an explicit `addJoin()` alias, a custom-group name, a
     * multi-level path, a price-set field — names something no source tree
     * can resolve, and is passed over without a word.
     *
     * @param list<string> $shadowedAliases aliases an explicit join rebound
     *
     * @return list<IdentifierRuleError>
     */
    public function checkJoinField(string $entity, string $path, string $clause, array $shadowedAliases): array
    {
        $parts = explode('.', $path);
        if (count($parts) !== 2) {
            return [];
        }
        [$alias, $field] = $parts;
        if (in_array($alias, $shadowedAliases, true)) {
            return [];
        }
        $target = Api4Catalog::joins($entity)[$alias] ?? null;
        if ($target === null) {
            return [];
        }

        return $this->checkField($target, $field, $path . ' in ' . $clause);
    }

    /**
     * The core entity an unknown name is probably a misspelling of.
     *
     * Short names are exempt: two edits away from a four-letter entity is
     * not a typo, it is a different word.
     */
    private static function nearestEntity(string $entity): ?string
    {
        $length = strlen($entity);
        if ($length < 4) {
            return null;
        }
        $tolerance = $length >= 6 ? 2 : 1;

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (array_keys(Api4Catalog::ENTITIES) as $known) {
            $distance = levenshtein(strtolower($entity), strtolower((string) $known));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $known;
            }
        }

        return $bestDistance <= $tolerance ? $best : null;
    }

    /** Clauses that write, and therefore reach the BAO. */
    private static function isWriteClause(string $clause): bool
    {
        return $clause === 'values' || strtolower($clause) === 'addvalue()';
    }

    /**
     * Names the catalog is allowed to have an opinion about.
     *
     * A dot is a join or a custom field (`address_primary.city`,
     * `custom.my_group.my_field`), whose right-hand side lives on another
     * entity or only on a configured site. A star, a space, a parenthesis
     * or a quote means a SQL expression (`COUNT(*) AS total`), not a field.
     * `row_count` is SearchKit's synthetic column.
     */
    private static function isCheckableFieldName(string $field): bool
    {
        if ($field === '' || $field === 'row_count') {
            return false;
        }
        foreach (['.', '*', '(', ')', ' ', '"', "'", '@'] as $needle) {
            if (str_contains($field, $needle)) {
                return false;
            }
        }
        foreach (Api4Catalog::DYNAMIC_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($field, $prefix)) {
                return false;
            }
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(:[A-Za-z_]+)?$/', $field) === 1;
    }

    private function entityIsKnown(string $entity): bool
    {
        if (Api4Catalog::knowsEntity($entity)) {
            return true;
        }
        foreach (Api4Catalog::DYNAMIC_PREFIXES as $prefix) {
            if (str_starts_with($entity, $prefix)) {
                return true;
            }
        }
        // Anything the analysis can see a class for: the extension's own
        // entities, and those of the extensions it declares a dependency on.
        if ($this->reflectionProvider->hasClass('Civi\\Api4\\' . $this->entityClass($entity))) {
            return true;
        }

        return in_array($entity, $this->ownEntities(), true);
    }

    /** The php class behind an entity name — `Case` lives in CiviCase. */
    private function entityClass(string $entity): string
    {
        $class = array_search($entity, Api4Catalog::CLASS_ALIASES, true);

        return $class === false ? $entity : $class;
    }

    /**
     * Entity names the analysed extension defines itself.
     *
     * Kept as a fallback next to the reflection lookup: an entity class the
     * autoloader does not reach is still this extension's business.
     *
     * @return list<string>
     */
    private function ownEntities(): array
    {
        if ($this->ownEntities !== null) {
            return $this->ownEntities;
        }

        $this->ownEntities = [];
        foreach (glob($this->extensionDir . '/Civi/Api4/*.php') ?: [] as $file) {
            $this->ownEntities[] = basename($file, '.php');
        }

        return $this->ownEntities;
    }

    /**
     * Field names in the params array of civicrm_api4().
     *
     * @return list<array{string, string}> field name and the clause it came from
     */
    public function fieldsFromParams(Type $params, Scope $scope): array
    {
        $arrays = $params->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $entries = self::entries($arrays[0]);
        // `SUM(qty) AS total` makes `total` a legal name in orderBy.
        $aliases = [];
        if (isset($entries['select'])) {
            foreach ($this->listOfStrings($entries['select']) as $select) {
                if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $select, $m) === 1) {
                    $aliases[] = $m[1];
                }
            }
        }

        $found = [];
        foreach ($entries as $key => $value) {
            switch ($key) {
                case 'select':
                    foreach ($this->listOfStrings($value) as $field) {
                        $found[] = [$field, 'select'];
                    }
                    break;

                case 'where':
                    foreach ($this->fieldsFromWhere($value) as $field) {
                        $found[] = [$field, 'where'];
                    }
                    break;

                case 'orderBy':
                case 'values':
                    foreach ($this->keys($value) as $field) {
                        $found[] = [$field, $key];
                    }
                    break;
            }
        }

        return array_values(array_filter(
            $found,
            static fn (array $entry): bool => !in_array($entry[0], $aliases, true),
        ));
    }

    /**
     * Field names in a where clause: [['field', '=', 1], ['OR', [...]]].
     *
     * @return list<string>
     */
    public function fieldsFromWhere(Type $where, int $depth = 0): array
    {
        $arrays = $where->getConstantArrays();
        if (count($arrays) !== 1 || $depth > 4) {
            return [];
        }
        $found = [];
        foreach ($arrays[0]->getValueTypes() as $clause) {
            $clauseArrays = $clause->getConstantArrays();
            if (count($clauseArrays) !== 1) {
                continue;
            }
            $parts = $clauseArrays[0]->getValueTypes();
            $first = isset($parts[0]) ? $this->literalStringFromType($parts[0]) : null;
            if ($first === null) {
                continue;
            }
            if (in_array(strtoupper($first), self::CLAUSE_OPERATORS, true)) {
                if (isset($parts[1])) {
                    $found = array_merge($found, $this->fieldsFromWhere($parts[1], $depth + 1));
                }
                continue;
            }
            $found[] = $first;
        }

        return $found;
    }

    /** @return list<string> */
    public function listOfStrings(Type $type): array
    {
        $arrays = $type->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $strings = [];
        foreach ($arrays[0]->getValueTypes() as $value) {
            $string = $this->literalStringFromType($value);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /** @return list<string> */
    public function keys(Type $type): array
    {
        $arrays = $type->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $keys = [];
        foreach ($arrays[0]->getKeyTypes() as $key) {
            $string = $this->literalStringFromType($key);
            if ($string !== null) {
                $keys[] = $string;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, Type>
     */
    private static function entries(ConstantArrayType $array): array
    {
        $entries = [];
        foreach ($array->getKeyTypes() as $i => $key) {
            $strings = $key->getConstantStrings();
            if (count($strings) === 1) {
                $entries[$strings[0]->getValue()] = $array->getValueTypes()[$i];
            }
        }

        return $entries;
    }
}
