<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4Contract;
use PHPUnit\Framework\TestCase;

/** The field verdict the rules and the live getFields drift test share. */
final class Api4ContractTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function verdicts(): iterable
    {
        yield 'catalog field' => ['Activity', 'subject', 'where', false];
        yield 'runtime spec field' => ['Activity', 'tags', 'where', false];
        yield 'typo' => ['Activity', 'subjct', 'where', true];
        yield 'option suffix of a real field' => ['Activity', 'status_id:label', 'select', false];
        yield 'option suffix of a typo' => ['Activity', 'statu_id:label', 'select', true];
        yield 'field every managed entity gets' => ['Group', 'has_base', 'where', false];
        yield 'join path' => ['Activity', 'source_contact_id.display_name', 'where', false];
        yield 'camelCase control param in values' => ['Activity', 'skipStatusCal', 'values', false];
        yield 'camelCase control param in addValue()' => ['Activity', 'skipStatusCal', 'addValue()', false];
        yield 'camelCase name in a read' => ['Activity', 'skipStatusCal', 'where', true];
        yield 'entity without a complete field list' => ['AfformBehavior', 'anything', 'where', false];
        yield 'unknown entity' => ['MyextThing', 'anything', 'where', false];
    }

    /** @dataProvider verdicts */
    public function testRejectsField(string $entity, string $field, string $clause, bool $rejected): void
    {
        self::assertSame($rejected, Api4Contract::rejectsField($entity, $field, $clause));
    }
}
