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

    /** The right-hand side of an implicit join is a field of its target. */
    public function joinTypo(): void
    {
        Contact::get(false)
            ->addSelect('address_primary.no_such_field')
            ->addWhere('email_primary.nope', '=', 'x')
            ->execute();
    }

    /** An explicit alias names a join the catalog cannot resolve. */
    public function explicitJoin(): void
    {
        Contact::get(false)
            ->addJoin('Address AS myalias', 'LEFT')
            ->addSelect('myalias.anything', 'myalias.no_such_field')
            ->execute();
    }

    /** An explicit join may rebind an implicit name; then it is not ours. */
    public function shadowedJoin(): void
    {
        Contact::get(false)
            ->addJoin('Address AS address_primary', 'LEFT')
            ->addSelect('address_primary.no_such_field')
            ->execute();
    }

    /** Multi-level paths and custom groups resolve on a live site only. */
    public function unresolvablePaths(): void
    {
        Contact::get(false)
            ->addSelect('address_primary.country_id.nonsense', 'my_custom_group.whatever')
            ->execute();
    }

    /** A chain kept in a variable addresses the same entity. */
    public function viaVariable(): void
    {
        $query = Contact::get(false);
        $query->addSelect('street_address');
        $query->execute();
    }

    /** getFields filters field definitions, whose columns are not Contact's. */
    public function metadata(): void
    {
        Contact::getFields(false)
            ->addSelect('name', 'label', 'input_type')
            ->addWhere('fk_entity', '=', 'Address')
            ->execute();
    }

    /** Core applies the clauses at execute(): an alias selected later still binds. */
    public function aliasSelectedLater(): void
    {
        Contact::get(false)->addOrderBy('cnt')->addOrderBy('srot_name')->addSelect('COUNT(id) AS cnt')->execute();
    }

    /** Named arguments bind by name. */
    public function namedArguments(): void
    {
        Contact::get(false)->addOrderBy(direction: 'DESC', fieldName: 'sort_name')->execute();
        Contact::get(false)->addWhere(value: 'Individual', fieldName: 'contact_type', op: '=')->execute();
        Contact::get(false)->addWhere(value: 'x', fieldName: 'frist_name', op: '=')->execute();
    }

    /** Builders held in variables, whatever action class core returns. */
    public function variables(): void
    {
        $q = Contact::get(false);
        $q->addWhere('frist_name', '=', 1);
        $a = \Civi\Api4\Activity::get(false);
        $a->addSelect('subjcet');
        $c = Contact::create(false);
        $c->addValue('frist_name', 'x');
        $n = Contact::get(false);
        $n->addSelect('COUNT(id) AS total');
        $n->addOrderBy('total');
    }

    /** Reassigned, or handed to code that may add an alias: not judged. */
    public function variablesOutOfSight(bool $flag): void
    {
        $q = Contact::get(false);
        if ($flag) {
            $q = \Civi\Api4\Activity::get(false);
        }
        $q->addSelect('subject');
        $h = Contact::get(false);
        $this->decorate($h);
        $h->addOrderBy('added_elsewhere');
    }

    private function decorate(object $query): void
    {
    }

    /** Fields core's spec providers add at runtime. */
    public function runtimeFields(): void
    {
        \Civi\Api4\Activity::get(false)->addWhere('tags', 'IN', [1])->execute();
        \Civi\Api4\Group::get(false)->addSelect('_depth', '_descendents')->execute();
        \Civi\Api4\Group::get(false)->addSelect('tags')->execute();
    }

    /** Aliases defined through setSelect(), literal or from a variable. */
    public function setSelectAliases(): void
    {
        Contact::get(false)->setSelect(['contact_type', 'COUNT(id) AS cnt'])->addOrderBy('cnt')->execute();
        $select = ['contact_type', 'COUNT(id) AS total'];
        Contact::get(false)->setSelect($select)->addOrderBy('total')->execute();
    }
}
