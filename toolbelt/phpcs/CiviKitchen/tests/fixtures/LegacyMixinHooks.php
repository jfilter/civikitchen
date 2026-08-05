<?php

// Fixture: legacy hook implementations that should now be handled by standard
// mixins and conventional files. Exact line numbers asserted by the test.

function civikitchen_fixture_civicrm_managed(&$entities) {
  $entities = [];
}
function civikitchen_fixture_civicrm_navigationMenu(&$menu) {
  $menu = [];
}
function civikitchen_fixture_civicrm_alterSettingsMetaData(&$settings, $domainID, $profile) {
  $settings['example'] = [];
}
function civikitchen_fixture_civicrm_entityTypes(&$entityTypes) {
  $entityTypes = [];
}
function civikitchen_fixture_civicrm_angularModules(&$angularModules) {
  $angularModules = [];
}
