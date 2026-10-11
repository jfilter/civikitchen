<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;

/**
 * The constant-value questions the APIv4 rules ask of an inferred type.
 * Anything not fully literal answers null or empty, so the rules stay silent.
 */
final class Types
{
    /** The single literal string a type holds, or null. */
    public static function literalString(?Type $type): ?string
    {
        $strings = self::literalStrings($type);

        return $strings !== null && count($strings) === 1 ? $strings[0] : null;
    }

    /** Every literal string of a union of literal strings, or null when one member is not one. */
    public static function literalStrings(?Type $type): ?array
    {
        if ($type === null) {
            return null;
        }
        $strings = [];
        foreach ($type->atomicTypes as $atomic) {
            $string = Type::fromAtomic($atomic)->getLiteralString();
            if ($string === null) {
                return null;
            }
            $strings[] = $string;
        }

        return $strings;
    }

    /**
     * The entries of a sealed array literal type: key => value type, optional
     * entries included. Null for anything else, unions of arrays included.
     *
     * @return ?list<array{key: int|string, value: Type}>
     */
    public static function entries(?Type $type): ?array
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }
        $array = $type->atomicTypes[0];
        if ($array instanceof ListType && $array->knownElements !== null && $array->knownCount !== null) {
            return array_map(static fn ($element) => ['key' => $element->index, 'value' => $element->type], array_values($array->knownElements));
        }
        if ($array instanceof KeyedArrayType && $array->knownItems !== null && $array->keyType === null) {
            $entries = [];
            foreach ($array->knownItems as $item) {
                if ($item->key->kind === ArrayKeyKind::ClassLikeConstant || $item->key->value === null) {
                    return null;
                }
                $entries[] = ['key' => $item->key->value, 'value' => $item->type];
            }

            return $entries;
        }
        if ($array instanceof KeyedArrayType && $array->knownItems === null && $array->keyType === null && !$array->nonEmpty) {
            return [];
        }

        return null;
    }

    /**
     * Literal strings among a sealed array's values.
     *
     * @return list<string>
     */
    public static function listOfStrings(?Type $type): array
    {
        $strings = [];
        foreach (self::entries($type) ?? [] as $entry) {
            $string = self::literalString($entry['value']);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /** String keys of a sealed array. */
    public static function keys(?Type $type): array
    {
        $keys = [];
        foreach (self::entries($type) ?? [] as $entry) {
            if (is_string($entry['key'])) {
                $keys[] = $entry['key'];
            }
        }

        return $keys;
    }

    /**
     * Field names in a where clause: [['field', '=', 1], ['OR', [...]]].
     *
     * @return list<string>
     */
    public static function fieldsFromWhere(?Type $where, int $depth = 0): array
    {
        $clauses = self::entries($where);
        if ($clauses === null || $depth > 4) {
            return [];
        }
        $found = [];
        foreach ($clauses as $clause) {
            $parts = self::entries($clause['value']);
            $first = $parts === null || !isset($parts[0]) ? null : self::literalString($parts[0]['value']);
            if ($first === null) {
                continue;
            }
            if (in_array(strtoupper($first), ['AND', 'OR', 'NOT'], true)) {
                if (isset($parts[1])) {
                    $found = array_merge($found, self::fieldsFromWhere($parts[1]['value'], $depth + 1));
                }
                continue;
            }
            $found[] = $first;
        }

        return $found;
    }
}
