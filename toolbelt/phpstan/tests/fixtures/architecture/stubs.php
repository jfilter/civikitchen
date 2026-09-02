<?php

declare(strict_types=1);

// Classes the consumer fixture references without a checkout to resolve them from.
namespace {
    final class CRM_Core_DAO_Stub
    {
        public static function noop(): void
        {
        }
    }

    final class CRM_Search_Thing
    {
        public static function noop(): void
        {
        }
    }

    final class CRM_Missing_Thing
    {
        public static function noop(): void
        {
        }
    }

    final class CRM_Mismatch_Thing
    {
        public static function noop(): void
        {
        }
    }
}

namespace Civi\Api4 {
    final class ContactStub
    {
        public static function get(): void
        {
        }
    }
}
