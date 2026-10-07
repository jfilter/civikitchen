<?php

use CiviKitchen\Rector\Tests\Fixture\Helper;

function contactNames(): array {
    $result = civicrm_api3('Contact', 'get', ['contact_type' => 'Individual', 'return' => ['display_name']]);
    $names = [];
    foreach ($result['values'] as $id => $contact) {
        $names[$id] = $contact['display_name'];
    }
    if ($result['count'] > 0 && !empty($result['values']) && isset($result['values'][3])) {
        Helper::show($result['values']);
    }

    return $names + array_column($result['values'], 'display_name') + ["{$result['count']}"];
}

function eachCallItsOwnShape(): int {
    $rows = civicrm_api3('Contact', 'get', ['sequential' => 1, 'id' => 3]);
    echo count($rows['values']), $rows['values'][0]['id'] ?? '';
    $rows = civicrm_api3('Contact', 'get', ['id' => 4, 'return' => 'id,display_name']);

    return $rows['count'];
}

function otherResultInParams(): array {
    $page = civicrm_api3('Contact', 'get', ['id' => 1]);
    $first = civicrm_api3('Contact', 'get', ['id' => 3]);
    $page = civicrm_api3('Contact', 'get', ['options' => ['limit' => $first['count']]]);

    return ["${page['count']}", new \ArrayIterator($page['values'])];
}

class Lookup {

    public function inLoop(int ...$ids): array {
        $all = [];
        foreach ($ids as $id) {
            $found = civicrm_api3('Contact', 'get', ['id' => $id, 'sequential' => TRUE]);
            $all = array_merge($all, $found['values']);
        }

        return $all;
    }

}

?>
-----
<?php

use CiviKitchen\Rector\Tests\Fixture\Helper;

function contactNames(): array {
    $result = \Civi\Api4\Contact::get(false)->addWhere('contact_type', '=', 'Individual')->addSelect('display_name', 'id')->setLimit(25)->execute()->indexBy('id');
    $names = [];
    foreach ($result->getArrayCopy() as $id => $contact) {
        $names[$id] = $contact['display_name'];
    }
    if ($result->countFetched() > 0 && !empty($result->getArrayCopy()) && isset($result[3])) {
        Helper::show($result->getArrayCopy());
    }

    return $names + array_column($result->getArrayCopy(), 'display_name') + ["{$result->countFetched()}"];
}

function eachCallItsOwnShape(): int {
    $rows = \Civi\Api4\Contact::get(false)->addWhere('id', '=', 3)->setLimit(25)->execute();
    echo count($rows->getArrayCopy()), $rows[0]['id'] ?? '';
    $rows = \Civi\Api4\Contact::get(false)->addWhere('id', '=', 4)->addSelect('id', 'display_name')->setLimit(25)->execute()->indexBy('id');

    return $rows->countFetched();
}

function otherResultInParams(): array {
    $page = \Civi\Api4\Contact::get(false)->addWhere('id', '=', 1)->setLimit(25)->execute()->indexBy('id');
    $first = \Civi\Api4\Contact::get(false)->addWhere('id', '=', 3)->setLimit(25)->execute()->indexBy('id');
    $page = \Civi\Api4\Contact::get(false)->setLimit($first->countFetched())->execute()->indexBy('id');

    return ["{$page->countFetched()}", new \ArrayIterator($page->getArrayCopy())];
}

class Lookup {

    public function inLoop(int ...$ids): array {
        $all = [];
        foreach ($ids as $id) {
            $found = \Civi\Api4\Contact::get(false)->addWhere('id', '=', $id)->setLimit(25)->execute();
            $all = array_merge($all, $found->getArrayCopy());
        }

        return $all;
    }

}

?>
