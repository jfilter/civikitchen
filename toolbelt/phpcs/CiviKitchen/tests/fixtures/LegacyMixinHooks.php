<?php

// Fixture: legacy hook implementations that should now be handled by standard
// mixins and conventional files. No info.xml above this file, so any function
// ending in _<hook> counts. Exact line numbers asserted by the test.

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
function civikitchen_fixture_civicrm_xmlMenu(&$files) {
  $files = [];
}
function civikitchen_fixture_civicrm_caseTypes(&$caseTypes) {
  $caseTypes = [];
}
function civikitchen_fixture_civicrm_themes(&$themes) {
  $themes = [];
}
function civikitchen_fixture_civicrm_alterSettingsFolders(&$metaDataFolders) {
  $metaDataFolders = [];
}
function civikitchen_fixture_helper_civicrm_managed(&$entities) {
  $entities = [];
}
