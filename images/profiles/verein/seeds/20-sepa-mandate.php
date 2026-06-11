<?php
// Seed CiviSEPA: a creditor for the Verein and recurring direct-debit (RCUR)
// mandates for the members created by 10-verein.php — Vollmitglieder pay
// 120 EUR annually, Fördermitglieder 5 EUR monthly. Uses the CiviSEPA API3
// actions (the extension has no API4); IBANs are the well-known German test
// IBANs (valid checksums, no real accounts).
// Idempotent at each step (get-or-create creditor, skip when mandates exist),
// so a re-run after a partial failure finishes the job instead of skipping it.
// cv scr runs without a logged-in user, so every API call disables permission
// checks explicitly.

use Civi\Api4\Contact;
use Civi\Api4\Extension;
use Civi\Api4\FinancialType;

$installed = Extension::get(FALSE)
  ->addWhere('key', '=', 'org.project60.sepa')
  ->addWhere('status', '=', 'installed')
  ->execute()->count();
if (!$installed) {
  echo "org.project60.sepa not installed, skipping\n";
  return;
}

$mandates = civicrm_api3('SepaMandate', 'getcount', ['check_permissions' => 0]);
if (!empty($mandates['result'])) {
  echo "SEPA mandates already seeded, skipping\n";
  return;
}

$verein = Contact::get(FALSE)
  ->addWhere('organization_name', '=', 'Musterverein e.V.')
  ->execute()->first();
if (!$verein) {
  echo "Verein org not found - run 10-verein.php first\n";
  return;
}

$creditorIdentifier = 'DE98ZZZ09999999999';
$found = civicrm_api3('SepaCreditor', 'get', [
  'identifier' => $creditorIdentifier,
  'check_permissions' => 0,
]);
if (!empty($found['id'])) {
  $creditorId = $found['id'];
  echo "SEPA creditor {$creditorIdentifier} already present (id {$creditorId})\n";
}
else {
  $creditor = civicrm_api3('SepaCreditor', 'create', [
    'creditor_id' => $verein['id'],
    'identifier' => $creditorIdentifier,
    'name' => 'Musterverein e.V.',
    'label' => 'Musterverein e.V.',
    'address' => 'Vereinsweg 1, 10115 Berlin',
    'iban' => 'DE89370400440532013000',
    'bic' => 'COBADEFFXXX',
    'mandate_prefix' => 'MV',
    'currency' => 'EUR',
    'creditor_type' => 'SEPA',
    'mandate_active' => 1,
    'check_permissions' => 0,
  ]);
  $creditorId = $creditor['id'];
  echo "created SEPA creditor {$creditorIdentifier} (id {$creditorId})\n";
}

// Valid-checksum German test IBANs (public examples, not real accounts).
$testIbans = [
  'DE02120300000000202051',
  'DE02500105170137075030',
  'DE02100500000054540402',
  'DE02300209000106531065',
  'DE89370400440532013000',
];

$financialType = FinancialType::get(FALSE)
  ->addWhere('name', '=', 'Member Dues')
  ->execute()->single()['id'];

// Mandates: annual debit for Vollmitglieder, monthly for Fördermitglieder.
$members = Contact::get(FALSE)
  ->addSelect('id', 'display_name', 'membership.membership_type_id:name')
  ->addJoin('Membership AS membership', 'INNER')
  ->addWhere('membership.membership_type_id:name', 'IN', ['Vollmitgliedschaft', 'Fördermitgliedschaft'])
  ->execute();

$count = 0;
foreach ($members as $i => $m) {
  $isFull = $m['membership.membership_type_id:name'] === 'Vollmitgliedschaft';
  civicrm_api3('SepaMandate', 'createfull', [
    'contact_id' => $m['id'],
    'type' => 'RCUR',
    'iban' => $testIbans[$i % count($testIbans)],
    'bic' => 'COBADEFFXXX',
    'amount' => $isFull ? 120.00 : 5.00,
    'frequency_unit' => 'month',
    'frequency_interval' => $isFull ? 12 : 1,
    'financial_type_id' => $financialType,
    'start_date' => date('Y-m-d'),
    'cycle_day' => 1,
    'creditor_id' => $creditorId,
    'check_permissions' => 0,
  ]);
  $count++;
}

echo "created {$count} SEPA mandates\n";
