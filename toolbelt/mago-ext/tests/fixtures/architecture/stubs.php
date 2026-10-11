<?php

declare(strict_types=1);

// Core bases and a foreign extension's classes, one per dependency kind.
namespace {
    class CRM_Core_Form
    {
    }

    class CRM_Core_Page
    {
    }

    class CRM_Report_Form
    {
    }

    class CRM_Report_Form_Contribute_Detail extends CRM_Report_Form
    {
    }

    class CRM_Core_DAO
    {
        public const DB = 'db';
    }

    class CRM_Foreign_Base
    {
    }

    interface CRM_Foreign_Contract
    {
    }

    trait CRM_Foreign_Behaviour
    {
    }

    class CRM_Foreign_Created
    {
    }

    class CRM_Foreign_Statics
    {
        public const LIMIT = 1;

        public static int $count = 0;

        public static function make(): void
        {
        }
    }

    class CRM_Foreign_Param
    {
    }

    class CRM_Foreign_Returned
    {
    }

    class CRM_Foreign_Property
    {
    }

    class CRM_Foreign_DocParam
    {
    }

    class CRM_Foreign_DocReturn
    {
    }

    class CRM_Foreign_DocVar
    {
    }

    class CRM_Foreign_Generic
    {
    }

    class CRM_Foreign_Checked
    {
    }

    class CRM_Foreign_Failure extends Exception
    {
    }

    class CRM_Foreign_Named
    {
    }

    class CRM_Foreign_ClosureParam
    {
    }

    function CRM_Foreign_helper(): void
    {
    }

    const CRM_FOREIGN_FLAG = 1;
}

namespace Civi\Foreign {
    #[\Attribute]
    final class Marker
    {
    }

    final class Imported
    {
        public static function make(): void
        {
        }
    }
}
