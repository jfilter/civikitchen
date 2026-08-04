<?php

declare(strict_types=1);

namespace Civi\Api4 {
    class Widget
    {
        public static function create(bool $checkPermissions = true): Generic\DummyAction
        {
            return new Generic\DummyAction();
        }

        public static function createDraft(bool $checkPermissions = true): Generic\DummyAction
        {
            return new Generic\DummyAction();
        }

        public static function get(bool $checkPermissions = true): Generic\DummyAction
        {
            return new Generic\DummyAction();
        }
    }
}

namespace CiviKitchen\Fixtures\Support {
    /** Stand-in for the PSR-7 request the menu XML hands a page_callback. */
    class Request
    {
        public function getMethod(): string
        {
            return 'GET';
        }
    }

    class Response {}
}

namespace {
    /** The base whose run() IS a route. */
    class CRM_Core_Page
    {
        public function run(): void {}
    }
}
