<?php

// Fixture: hook implementations of the extension whose info.xml sits beside
// this file (<file>acme</file>). Only acme_civicrm_<hook> is a hook.

function acme_civicrm_managed(&$entities) {
  $entities = [];
}
function ACME_CIVICRM_MANAGED_unused() {
}
function Acme_Civicrm_XmlMenu(&$files) {
  $files = [];
}
function acme_civicrm_caseTypes(&$caseTypes) {
  $caseTypes = [];
}
function acme_civicrm_themes(&$themes) {
  $themes = [];
}
function acme_civicrm_alterSettingsFolders(&$metaDataFolders) {
  $metaDataFolders = [];
}
if (!function_exists('acme_civicrm_navigationMenu')) {
  function acme_civicrm_navigationMenu(&$menu) {
    $menu = [];
  }
}
function acme_helper_civicrm_managed(&$entities) {
  $entities = [];
}
function other_civicrm_managed(&$entities) {
  $entities = [];
}
function acme_civicrm_alterSettingsMetaData(&$settings) {
  $settings = [];
}
function acme_civicrm_buildForm($formName, &$form) {
  $form = NULL;
}
function acme_wrapper() {
  function acme_civicrm_entityTypes(&$entityTypes) {
    $entityTypes = [];
  }
}

class AcmeHooks {

  public function acme_civicrm_angularModules(&$angularModules) {
    $angularModules = [];
  }

}
