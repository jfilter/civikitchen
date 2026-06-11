<?php
// Seed the core Verein world: the association itself, member groups, German
// member contacts (email + address), membership types (Voll-/Förder-/Ehren-
// mitgliedschaft), memberships, and last year's membership-fee contributions.
// Runs via `cv scr` with CiviCRM booted. Idempotent: skips entirely when the
// Verein org already exists (the apply re-runs if an earlier first boot died
// before the provisioning marker was written).

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;

$existing = Contact::get(FALSE)
  ->addWhere('organization_name', '=', 'Musterverein e.V.')
  ->selectRowCount()
  ->execute();
if ($existing->count()) {
  echo "Verein already seeded, skipping\n";
  return;
}

\Civi::settings()->set('defaultCurrency', 'EUR');

$verein = Contact::create(FALSE)
  ->addValue('contact_type', 'Organization')
  ->addValue('organization_name', 'Musterverein e.V.')
  ->execute()->first();
Email::create(FALSE)
  ->addValue('contact_id', $verein['id'])
  ->addValue('email', 'kontakt@musterverein.example.de')
  ->execute();
Address::create(FALSE)
  ->addValue('contact_id', $verein['id'])
  ->addValue('location_type_id:name', 'Main')
  ->addValue('street_address', 'Vereinsweg 1')
  ->addValue('postal_code', '10115')
  ->addValue('city', 'Berlin')
  ->addValue('country_id:name', 'Germany')
  ->execute();
echo "created Musterverein e.V. (contact {$verein['id']})\n";

// Membership types: annual fees, rolling periods (join any time of year).
$types = [
  ['Vollmitgliedschaft', 120.00],
  ['Fördermitgliedschaft', 60.00],
  ['Ehrenmitgliedschaft', 0.00],
];
foreach ($types as [$name, $fee]) {
  MembershipType::create(FALSE)
    ->addValue('name', $name)
    ->addValue('member_of_contact_id', $verein['id'])
    ->addValue('financial_type_id:name', 'Member Dues')
    ->addValue('duration_unit', 'year')
    ->addValue('duration_interval', 1)
    ->addValue('period_type', 'rolling')
    ->addValue('minimum_fee', $fee)
    ->addValue('is_active', TRUE)
    ->execute();
}
echo "created 3 membership types\n";

foreach ([['vorstand', 'Vorstand'], ['aktive', 'Aktive Mitglieder'], ['newsletter', 'Newsletter']] as [$gname, $gtitle]) {
  Group::create(FALSE)
    ->addValue('name', $gname)
    ->addValue('title', $gtitle)
    ->addValue('is_active', TRUE)
    ->execute();
}
echo "created 3 groups\n";

// [first, last, street, postal code, city, membership type, groups]
// First three are the board (Vorstand); honorary members pay no fee.
$members = [
  ['Anna', 'Schneider', 'Lindenstraße 12', '10115', 'Berlin', 'Vollmitgliedschaft', ['vorstand', 'aktive', 'newsletter']],
  ['Thomas', 'Weber', 'Hauptstraße 45', '80331', 'München', 'Vollmitgliedschaft', ['vorstand', 'aktive', 'newsletter']],
  ['Sabine', 'Fischer', 'Gartenweg 3', '50667', 'Köln', 'Vollmitgliedschaft', ['vorstand', 'newsletter']],
  ['Michael', 'Wagner', 'Bahnhofstraße 78', '20095', 'Hamburg', 'Vollmitgliedschaft', ['aktive', 'newsletter']],
  ['Julia', 'Becker', 'Schulstraße 9', '70173', 'Stuttgart', 'Vollmitgliedschaft', ['aktive']],
  ['Stefan', 'Hoffmann', 'Kirchplatz 2', '60311', 'Frankfurt am Main', 'Vollmitgliedschaft', ['newsletter']],
  ['Katrin', 'Schäfer', 'Mühlenweg 17', '04109', 'Leipzig', 'Vollmitgliedschaft', ['aktive', 'newsletter']],
  ['Andreas', 'Koch', 'Am Markt 5', '28195', 'Bremen', 'Vollmitgliedschaft', []],
  ['Claudia', 'Bauer', 'Rosenstraße 22', '90402', 'Nürnberg', 'Vollmitgliedschaft', ['newsletter']],
  ['Martin', 'Richter', 'Bergstraße 8', '01067', 'Dresden', 'Vollmitgliedschaft', ['aktive']],
  ['Petra', 'Klein', 'Waldweg 31', '30159', 'Hannover', 'Vollmitgliedschaft', ['newsletter']],
  ['Jürgen', 'Wolf', 'Feldstraße 14', '40213', 'Düsseldorf', 'Vollmitgliedschaft', []],
  ['Birgit', 'Neumann', 'Seestraße 6', '24103', 'Kiel', 'Fördermitgliedschaft', ['newsletter']],
  ['Frank', 'Schwarz', 'Wiesengrund 19', '55116', 'Mainz', 'Fördermitgliedschaft', ['newsletter']],
  ['Susanne', 'Zimmermann', 'Heideweg 4', '99084', 'Erfurt', 'Fördermitgliedschaft', []],
  ['Ralf', 'Braun', 'Birkenallee 27', '66111', 'Saarbrücken', 'Fördermitgliedschaft', ['newsletter']],
  ['Monika', 'Krüger', 'Eichendorffstraße 11', '14467', 'Potsdam', 'Fördermitgliedschaft', []],
  ['Dieter', 'Hofmann', 'Goethestraße 33', '39104', 'Magdeburg', 'Fördermitgliedschaft', ['newsletter']],
  ['Heike', 'Hartmann', 'Schillerplatz 7', '19053', 'Schwerin', 'Fördermitgliedschaft', []],
  ['Uwe', 'Lange', 'Uferstraße 16', '65183', 'Wiesbaden', 'Fördermitgliedschaft', ['aktive']],
  ['Gisela', 'Schmitt', 'Dorfstraße 1', '93047', 'Regensburg', 'Fördermitgliedschaft', ['newsletter']],
  ['Werner', 'Krause', 'Ahornweg 23', '23552', 'Lübeck', 'Ehrenmitgliedschaft', ['newsletter']],
  ['Ingrid', 'Lehmann', 'Buchenstraße 10', '54290', 'Trier', 'Ehrenmitgliedschaft', []],
  ['Karl', 'Maier', 'Tannenweg 2', '78462', 'Konstanz', 'Ehrenmitgliedschaft', ['newsletter']],
];

$created = 0;
foreach ($members as $i => [$first, $last, $street, $plz, $city, $type, $groups]) {
  $contact = Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', $first)
    ->addValue('last_name', $last)
    ->execute()->first();
  $email = strtolower(strtr("{$first}.{$last}", ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])) . '@example-verein.de';
  Email::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('email', $email)
    ->execute();
  Address::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('location_type_id:name', 'Home')
    ->addValue('street_address', $street)
    ->addValue('postal_code', $plz)
    ->addValue('city', $city)
    ->addValue('country_id:name', 'Germany')
    ->execute();

  // Join dates spread over the past years (deterministic, index-based).
  $joined = date('Y-m-d', strtotime('-' . (3 + $i * 2) . ' months'));
  Membership::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('membership_type_id:name', $type)
    ->addValue('join_date', $joined)
    ->addValue('start_date', date('Y-m-d', strtotime('-2 months')))
    ->execute();

  // Last year's membership fee as a completed contribution (gives CiviBanking
  // something realistic to reconcile against).
  $fee = ['Vollmitgliedschaft' => 120.00, 'Fördermitgliedschaft' => 60.00, 'Ehrenmitgliedschaft' => 0.00][$type];
  if ($fee > 0) {
    Contribution::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('financial_type_id:name', 'Member Dues')
      ->addValue('total_amount', $fee)
      ->addValue('currency', 'EUR')
      ->addValue('receive_date', date('Y-m-d', strtotime('-' . (1 + $i % 11) . ' months')))
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('source', 'Mitgliedsbeitrag')
      ->execute();
  }

  foreach ($groups as $g) {
    GroupContact::create(FALSE)
      ->addValue('group_id:name', $g)
      ->addValue('contact_id', $contact['id'])
      ->addValue('status', 'Added')
      ->execute();
  }
  $created++;
}

echo "created {$created} members with memberships, contributions, and groups\n";
