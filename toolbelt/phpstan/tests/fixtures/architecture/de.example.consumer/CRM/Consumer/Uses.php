<?php

declare(strict_types=1);

/** Every dependency kind the boundary rule has to decide on. */
final class CRM_Consumer_Uses
{
    public function run(): void
    {
        CRM_Consumer_Helper::noop();
        CRM_Core_DAO_Stub::noop();
        Civi\Api4\ContactStub::get();
        CRM_Search_Thing::noop();
        CRM_Provider_Thing::noop();
        Civi\CiSibling\Thing::noop();
        CRM_Mounted_Thing::noop();
        CRM_Missing_Thing::noop();
        CRM_Mismatch_Thing::noop();
        CRM_Unrelated_Thing::noop();
    }
}
