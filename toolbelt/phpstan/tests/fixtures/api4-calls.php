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

    /** Named arguments bind by name; a spread binds nothing readable. */
    public function namedArguments(array $args): void
    {
        civicrm_api4(action: 'get', entity: 'Contact', params: ['select' => ['display_nam']]);
        civicrm_api4(action: 'gett', entity: 'Contact');
        civicrm_api4('Contact', params: ['select' => ['display_name']], action: 'get');
        civicrm_api4('Contact', ...$args);
    }

    /** php class names are case-insensitive. */
    public function classCase(): void
    {
        \civi\api4\contact::gett();
    }

    /** Runtime fields, and other extensions' entities next to core's. */
    public function runtimeFieldsAndEntities(): void
    {
        civicrm_api4(action: 'export', entity: 'Group', params: []);
        civicrm_api4('Contact', params: ['select' => ['frist_name']], action: 'get');
        civicrm_api4('Activity', 'get', ['where' => [['tags', 'IN', [1]]]]);
        civicrm_api4('Group', 'get', ['select' => ['_depth', '_descendents']]);
        civicrm_api4('Group', 'get', ['select' => ['tags']]);
        civicrm_api4('Contract', 'get');
        civicrm_api4('Project', 'get');
        civicrm_api4('Identity', 'get');
        civicrm_api4('Folder', 'get');
        civicrm_api4('Groups', 'get');
        civicrm_api4('Notes', 'get');
        civicrm_api4('Reports', 'get');
        civicrm_api4('Mail', 'get');
        civicrm_api4('Vote', 'get');
        civicrm_api4('Patch', 'get');
        civicrm_api4('Contatc', 'get');
        civicrm_api4('contact', 'get');
    }

    /** A first-class callable names the action as surely as a call. */
    public function callables(): void
    {
        $typo = \Civi\Api4\Contact::gte(...);
        $fine = \Civi\Api4\Contact::get(...);
    }
}
