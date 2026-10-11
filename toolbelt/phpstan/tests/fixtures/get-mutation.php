<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Routes;

use Civi\Api4\Widget;
use CiviKitchen\Fixtures\Support\Request;
use CiviKitchen\Fixtures\Support\Response;

/** A webhook that answers 405 to anything but POST — the good shape. */
final class WebhookEndpoint
{
    public static function handle(Request $request): Response
    {
        if ($request->getMethod() !== 'POST') {
            return new Response();
        }
        Widget::create(false)->execute();

        return new Response();
    }
}

/** The write is two hops down, and nothing looks at the request method. */
final class GreeterEndpoint
{
    public static function handle(Request $request): Response
    {
        return self::store();
    }

    private static function store(): Response
    {
        Widget::createDraft(false)->execute();
        \civicrm_api3('Contact', 'delete', ['id' => 1]);
        \civicrm_api4(action: 'create', entity: 'Contact', params: []);
        \crm_core_dao::executeQuery('UPDATE civicrm_widget SET id = 2');

        return new Response();
    }

    /** Not a route, and not called from one. */
    public static function unrelated(): void
    {
        Widget::create(false)->execute();
    }
}

/** A page class: run() is a route by inheritance, no catalog needed. */
final class WidgetPage extends \CRM_Core_Page
{
    public function run(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_widget WHERE id = 1');
    }
}

/** Reads only. */
final class ReportEndpoint
{
    public static function handle(Request $request): Response
    {
        Widget::get(false)->execute();
        \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_contact');

        return new Response();
    }
}

/** Not in the route catalog and not a page — nobody can GET this. */
final class InternalService
{
    public static function handle(Request $request): void
    {
        Widget::create(false)->execute();
    }
}

/** core's AJAX guard rejects anything that is not an XMLHttpRequest. */
final class AjaxPage extends \CRM_Core_Page
{
    public function run(): void
    {
        \CRM_Core_Page_AJAX::validateAjaxRequestMethod();
        Widget::update(false)->execute();
    }
}

/** Another AJAX helper is no guard. */
final class JsonPage extends \CRM_Core_Page
{
    public function run(): void
    {
        \CRM_Core_Page_AJAX::returnJsonResponse([]);
        Widget::create(false)->execute();
    }
}

trait CreatesWidgets
{
    public function run(): void
    {
        Widget::create(false)->execute();
    }
}

final class TraitPage extends \CRM_Core_Page
{
    use CreatesWidgets;
}

/** DAO and BAO writes, beside calls whose target is not a known write. */
final class LedgerPage extends \CRM_Core_Page
{
    public function run(string $entity, string $method, object $widget): void
    {
        \CRM_Core_BAO_Ledger::writeRecord([]);
        $ledgerBao = new \CRM_Core_BAO_Ledger();
        $ledgerBao->save();
        \CRM_Core_BAO_Ledger::findById(1)->delete();
        $dao = new \CRM_Core_DAO();
        $dao->query('INSERT INTO civicrm_ledger (id) VALUES (1)');
        $dao->find(true);
        $widget->save();
        \civicrm_api3($entity, 'create', []);
        \CRM_Core_DAO::$method();
        $this->{$method}();
    }
}

trait StoresLedger
{
    private function store(): void
    {
        \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_ledger (id) VALUES (2)');
    }
}

/** The handler is the class's own, the write sits in a trait helper. */
final class StorePage extends \CRM_Core_Page
{
    use StoresLedger;

    public function run(): void
    {
        $this->store();
    }
}

/** An anonymous page is still a page. */
function anonymous_page(): \CRM_Core_Page
{
    return new class extends \CRM_Core_Page {
        public function run(): void
        {
            Widget::create(false)->execute();
        }
    };
}

/** A page that only defines another page; the write is the inner one's alone. */
final class OuterPage extends \CRM_Core_Page
{
    public function run(): \CRM_Core_Page
    {
        return new class extends \CRM_Core_Page {
            public function run(): void
            {
                $this->helper();
                Widget::create(false)->execute();
            }

            private function helper(): void
            {
            }
        };
    }

    private function helper(): void
    {
        Widget::create(false)->execute();
    }
}

/** A request-method check inside a nested class guards nothing outside it. */
final class GuardLeakPage extends \CRM_Core_Page
{
    public function run(Request $request): void
    {
        $guard = new class {
            public function allows(Request $request): bool
            {
                return $request->getMethod() === 'POST';
            }
        };
        Widget::create(false)->execute();
    }
}

abstract class BasePage extends \CRM_Core_Page
{
}

trait RunsWidgets
{
    public function run(): void
    {
        Widget::create(false)->execute();
    }
}

/** Anonymous pages by inheritance and by trait; a non-page stays silent. */
function anonymous_pages(): array
{
    return [
        new class extends BasePage {
            public function run(): void
            {
                Widget::create(false)->execute();
            }
        },
        new class extends \CRM_Core_Page {
            use RunsWidgets;
        },
        new class implements \Countable {
            public function count(): int
            {
                return 0;
            }

            public function run(): void
            {
                Widget::create(false)->execute();
            }
        },
    ];
}

/** An anonymous object's constructor runs inside run(); its other methods do not. */
final class BuilderPage extends \CRM_Core_Page
{
    public function run(): object
    {
        return new class {
            public function __construct()
            {
                Widget::create(false)->execute();
            }

            public function later(): void
            {
                Widget::create(false)->execute();
            }
        };
    }
}
