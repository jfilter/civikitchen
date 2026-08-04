<?php

declare(strict_types=1);

namespace CiviKitchen\Fixtures\Api4Fields;

use Civi\Api4\Contact;

final class AddressExport
{
    /** Address fields do not exist on Contact — APIv4 returns them empty. */
    public function wrong(int $contactId): void
    {
        Contact::get(false)
            ->addWhere('id', '=', $contactId)
            ->addSelect('display_name', 'street_address', 'postal_code', 'city', 'country_id:name')
            ->execute();
    }

    /** The same data through the implicit join, plus an option suffix. */
    public function right(int $contactId): void
    {
        Contact::get(false)
            ->addWhere('id', '=', $contactId)
            ->addSelect(
                'display_name',
                'address_primary.street_address',
                'address_primary.postal_code',
                'address_primary.city',
                'address_primary.country_id:name',
            )
            ->addOrderBy('sort_name')
            ->execute();
    }

    /** Option-value suffixes on a real Contact field are field names too. */
    public function suffixes(): void
    {
        Contact::get(false)
            ->addSelect('contact_type:label', 'preferred_language:name', 'gender_id:abbr')
            ->execute();
    }

    /** A chain kept in a variable addresses the same entity. */
    public function viaVariable(): void
    {
        $query = Contact::get(false);
        $query->addSelect('street_address');
        $query->execute();
    }
}
