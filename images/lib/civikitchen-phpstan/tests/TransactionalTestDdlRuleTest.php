<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\TransactionalTestDdlRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TransactionalTestDdlRule>
 */
final class TransactionalTestDdlRuleTest extends RuleTestCase
{
    private const ADVICE = ' — MySQL commits implicitly on DDL, which ends the test transaction and leaves the rows '
        . 'behind — move it to setUpHeadless().';

    public function testSchemaChangesInsideTheTransactionAreReported(): void
    {
        $this->analyse(
            [
                __DIR__ . '/fixtures/api4-stubs.php',
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/civi-test-stubs.php',
                __DIR__ . '/fixtures/transactional-test-ddl.php',
            ],
            [
                ['CustomField::create() in a transactional test' . self::ADVICE, 16],
                ["civicrm_api4('CustomGroup', 'create') in a transactional test" . self::ADVICE, 19],
                ['CREATE statement in a transactional test' . self::ADVICE, 20],
                ['DROP statement in a transactional test' . self::ADVICE, 21],
                ['extension install() in a transactional test' . self::ADVICE, 23],
                ['ALTER statement in a transactional test' . self::ADVICE, 28],
                ['RENAME statement in a transactional test' . self::ADVICE, 53],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new TransactionalTestDdlRule();
    }
}
