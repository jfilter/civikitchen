<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4Contract;
use CiviKitchen\PHPStan\Api4FluentFieldRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The regression guard for the address-fields-on-Contact class of bug.
 *
 * `addSelect('street_address')` on a Contact is accepted by APIv4 and comes
 * back empty; only the catalog can say that the field lives on Address. The
 * negative half of the fixture is the more important one — the join form and
 * the option-value suffixes are how the correct code looks, and a rule that
 * flags those would be turned off fleet-wide within a day.
 *
 * @extends RuleTestCase<Api4FluentFieldRule>
 */
final class Api4FluentFieldRuleTest extends RuleTestCase
{
    public function testAddressFieldsOnContactAreReportedAndJoinsAreNot(): void
    {
        $this->analyse(
            [__DIR__ . '/fixtures/api4-stubs.php', __DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-fluent-fields.php'],
            [
                ['APIv4 field Contact.street_address does not exist in CiviCRM 6.16.2 — addSelect()', 14],
                ['APIv4 field Contact.postal_code does not exist in CiviCRM 6.16.2 — addSelect()', 14],
                ['APIv4 field Contact.city does not exist in CiviCRM 6.16.2 — addSelect()', 14],
                ['APIv4 field Contact.country_id does not exist in CiviCRM 6.16.2 — addSelect()', 14],
                ['APIv4 field Contact.street_address does not exist in CiviCRM 6.16.2 — addSelect()', 48],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new Api4FluentFieldRule(new Api4Contract(__DIR__, self::createReflectionProvider()));
    }
}
