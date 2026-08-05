<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\GetMutationRule;
use CiviKitchen\PHPStan\RouteCatalog;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<GetMutationRule>
 */
final class GetMutationRuleTest extends RuleTestCase
{
    private const ADVICE = ' — a route a browser can GET must not change data — check the request method, '
        . 'or document the exception with @phpstan-ignore ck.route.mutationOnGet.';

    private ?string $catalog = null;

    private ?string $extensionDir = null;

    public function testWritesReachableFromAGetRouteAreReported(): void
    {
        $this->catalog = __DIR__ . '/fixtures/route-catalog.json';
        $this->analyse(
            [
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/route-stubs.php',
                __DIR__ . '/fixtures/get-mutation.php',
            ],
            [
                [
                    'Widget::createDraft() writes from CiviKitchen\Fixtures\Routes\GreeterEndpoint::handle(), '
                    . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                    35,
                ],
                [
                    "civicrm_api3('Contact', 'delete') writes from CiviKitchen\\Fixtures\\Routes\\GreeterEndpoint::handle(), "
                    . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                    36,
                ],
                [
                    'DELETE in CRM_Core_DAO::executeQuery() writes from CiviKitchen\Fixtures\Routes\WidgetPage::run(), '
                    . 'which answers GET civicrm/widget' . self::ADVICE,
                    58,
                ],
            ],
        );
    }

    /**
     * A route declared only in xml/Menu, with no generated catalog beside
     * it, must still be seen — otherwise adding a route and forgetting to
     * regenerate would silently switch the gate off.
     */
    public function testRoutesAreReadFromTheMenuXmlWhenNoCatalogExists(): void
    {
        $this->catalog = null;
        $this->extensionDir = __DIR__ . '/fixtures/repo';
        $this->analyse(
            [
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/route-stubs.php',
                __DIR__ . '/fixtures/get-mutation.php',
            ],
            [
                [
                    'Widget::createDraft() writes from CiviKitchen\Fixtures\Routes\GreeterEndpoint::handle(), '
                    . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                    35,
                ],
                [
                    "civicrm_api3('Contact', 'delete') writes from CiviKitchen\\Fixtures\\Routes\\GreeterEndpoint::handle(), "
                    . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                    36,
                ],
                [
                    'DELETE in CRM_Core_DAO::executeQuery() writes from CiviKitchen\Fixtures\Routes\WidgetPage::run(), '
                    . 'which answers GET civicrm/widget' . self::ADVICE,
                    58,
                ],
            ],
        );
    }

    /** No routes anywhere: only the page class, which is one by inheritance. */
    public function testPageClassesAreRoutesWithoutAnyDeclaration(): void
    {
        $this->catalog = null;
        $this->extensionDir = null;
        $this->analyse(
            [
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/route-stubs.php',
                __DIR__ . '/fixtures/get-mutation.php',
            ],
            [
                [
                    'DELETE in CRM_Core_DAO::executeQuery() writes from CiviKitchen\Fixtures\Routes\WidgetPage::run(), '
                    . 'which is reachable by GET' . self::ADVICE,
                    58,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new GetMutationRule(new RouteCatalog($this->catalog, $this->extensionDir));
    }
}
