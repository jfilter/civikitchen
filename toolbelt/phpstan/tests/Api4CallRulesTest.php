<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4Catalog;
use CiviKitchen\PHPStan\Api4Contract;
use CiviKitchen\PHPStan\Api4FunctionCallRule;
use CiviKitchen\PHPStan\Api4StaticCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * civicrm_api4() and `\Civi\Api4\X::action()`: typos are reported, a
 * differently cased action and the metadata actions' own columns are not.
 *
 * @extends RuleTestCase<Rule<\PhpParser\Node>>
 */
final class Api4CallRulesTest extends RuleTestCase
{
    private ?Rule $rule = null;

    public function testFunctionCalls(): void
    {
        $this->rule = new Api4FunctionCallRule($this->contract());
        $this->analyse($this->files(), [
            ['APIv4 field Contact.display_nam does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — select', 13],
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — civicrm_api4()', 14],
            ['APIv4 field Contact.contact_typ does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — groupBy', 16],
        ]);
    }

    public function testStaticCalls(): void
    {
        $this->rule = new Api4StaticCallRule($this->contract());
        $this->analyse($this->files(), [
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — fluent APIv4 call', 15],
        ]);
    }

    protected function getRule(): Rule
    {
        return $this->rule ?? throw new \LogicException('set the rule before analysing');
    }

    private function contract(): Api4Contract
    {
        return new Api4Contract(__DIR__, self::createReflectionProvider());
    }

    /** @return list<string> */
    private function files(): array
    {
        return [__DIR__ . '/fixtures/api4-stubs.php', __DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-calls.php'];
    }
}
