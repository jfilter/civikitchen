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
