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
    $result = civicrm_api4('Contact', 'get', ['where' => [['contact_type', '=', 'Individual']], 'select' => ['display_name', 'id'], 'limit' => 25, 'checkPermissions' => false], 'id');
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
    $rows = civicrm_api4('Contact', 'get', ['where' => [['id', '=', 3]], 'limit' => 25, 'checkPermissions' => false]);
    echo count($rows->getArrayCopy()), $rows[0]['id'] ?? '';
    $rows = civicrm_api4('Contact', 'get', ['where' => [['id', '=', 4]], 'select' => ['id', 'display_name'], 'limit' => 25, 'checkPermissions' => false], 'id');

    return $rows->countFetched();
}

function otherResultInParams(): array {
    $page = civicrm_api4('Contact', 'get', ['where' => [['id', '=', 1]], 'limit' => 25, 'checkPermissions' => false], 'id');
    $first = civicrm_api4('Contact', 'get', ['where' => [['id', '=', 3]], 'limit' => 25, 'checkPermissions' => false], 'id');
    $page = civicrm_api4('Contact', 'get', ['limit' => $first->countFetched(), 'checkPermissions' => false], 'id');

    return ["{$page->countFetched()}", new \ArrayIterator($page->getArrayCopy())];
}

class Lookup {

    public function inLoop(int ...$ids): array {
        $all = [];
        foreach ($ids as $id) {
            $found = civicrm_api4('Contact', 'get', ['where' => [['id', '=', $id]], 'limit' => 25, 'checkPermissions' => false]);
            $all = array_merge($all, $found->getArrayCopy());
        }

        return $all;
    }

}

?>
