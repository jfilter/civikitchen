<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Api4Calls;

use Civi\Api4\Contact;

final class Calls
{
    public function typos(): void
    {
        civicrm_api4('Contact', 'get', ['select' => ['display_nam']]);
        civicrm_api4('Contact', 'gett');
        Contact::gett();
        civicrm_api4('Contact', 'get', ['select' => ['COUNT(id) AS n'], 'groupBy' => ['contact_typ', 'n']]);
    }

    /** php method names are case-insensitive; the call runs `get`. */
    public function actionCase(): void
    {
        civicrm_api4('Activity', 'Get', ['select' => ['subject']]);
        civicrm_api4('Activity', 'get', ['select' => ['activity_type_id', 'COUNT(id) AS n'], 'groupBy' => ['activity_type_id', 'n']]);
        \Civi\Api4\Activity::Get();
    }

    /** getFields filters field definitions, whose columns are not Contact's. */
    public function metadata(): void
    {
        civicrm_api4('Contact', 'getFields', ['select' => ['name', 'label', 'input_type'], 'where' => [['fk_entity', '=', 'Address']]]);
    }
}
