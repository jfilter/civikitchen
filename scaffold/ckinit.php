#!/usr/bin/env php
<?php
declare(strict_types=1);

$templateDir = __DIR__ . '/template/extension';
$yamlAutoload = dirname(__DIR__) . '/packages/civikitchen-scenario-schema/vendor/autoload.php';
if (!is_file($yamlAutoload)) {
  fwrite(STDERR, "ckinit: YAML parser dependency is missing; run composer install --working-dir=packages/civikitchen-scenario-schema\n");
  exit(2);
}

/**
 * Template files civikitchen OWNS: identical in every conforming repo, safe to
 * overwrite on --update and compared on --check. Everything else in the
 * template is seeded once (composer.json, phpstan.neon.dist, the dev compose
 * file, .gitignore) and then belongs to the extension — repos edit those, so
 * ckinit never touches them again.
 *
 * A repo that must deviate on a managed file declares it in civikitchen.yaml:
 *
 *   policy.template_custom.paths: [.docker/docker-compose.ci.yml]
 */
const MANAGED_FILES = [
  '.gitattributes',
  '.github/workflows/ci.yml',
  '.docker/docker-compose.ci.yml',
  '.docker/db-init/01-grants.sql',
  '.docker/init.d/README.md',
  'renovate.json',
  'phpstanBootstrap.php',
  'tests/phpunit/bootstrap.php',
  'tests/phpunit/ckHeadless.php',
  'tests/e2e/lib.sh',
];

/**
 * Seeded files, listed explicitly rather than "everything else": a template
 * file in neither list aborts every ckinit run. Without that, a new template
 * file would default to seeded — copied once, then never updated — which for
 * a workflow or bootstrap file is exactly the wrong silent default.
 */
const SEEDED_FILES = [
  '.gitignore',
  'civikitchen.yaml',
  '.docker/docker-compose.yml',
  'composer.json',
  // The file calls itself a "project layer" and it means it: repos scope out
  // generated code and tune severities there. Managing it byte-identically
  // turned five green repos red on the first fleet rollout. The CiviKitchen
  // STANDARD stays central (it ships in the image); the layer is the repo's.
  'phpcs.xml.dist',
  'phpstan.neon.dist',
  // Opt-in test analysis. Seeded, never managed: its mere existence turns a
  // second CI gate on, so pushing it into existing repos would fail them.
  'phpstan-tests.neon.dist',
  'phpunit.xml.dist',
];

/**
 * Seeded files whose ABSENCE is never drift.
 *
 * A new repo gets them; an existing one is not made to. phpstan-tests.neon.dist
 * is an opt-in gate — its existence is the switch CI reads — so reporting it
 * missing would turn the drift check into the forced rollout the opt-in was
 * meant to avoid. --update therefore does not create these either.
 */
const OPTIONAL_FILES = [
  'phpstan-tests.neon.dist',
];

function usage(int $status = 2): never {
  $stream = $status === 0 ? STDOUT : STDERR;
  fwrite($stream, <<<'TXT'
ckinit — add or refresh the CiviKitchen development standard in a civix extension.

Usage:
  scaffold/ckinit.php [--force] <extension-directory>    seed the template
  scaffold/ckinit.php --update <extension-directory>     refresh managed files
  scaffold/ckinit.php --check <extension-directory>      report drift, exit 1 on any

The target must contain info.xml. Files from scaffold/template/extension are copied
recursively; __EXTKEY__ is replaced with info.xml's <file> value,
__VENDOR__ with the vendor segment of the extension key and __RENOVATE_PRESET__
with the renovate_preset policy key (default config:recommended).

Seeding preserves existing files unless --force is given. --update rewrites
only the MANAGED files (CI caller, test bootstraps, CI compose stack — the
ones meant to be identical everywhere) and creates whatever is missing;
seeded files the repo has edited (composer.json, phpcs.xml.dist,
phpstan.neon.dist, dev compose, .gitignore) are never touched. --check is
the dry twin for CI.

Some managed files carry marked blocks:
  # BEGIN CIVIKITCHEN MANAGED <name> … # END CIVIKITCHEN MANAGED <name>
Only the blocks are managed (compared, refreshed); what a repo writes outside
them — extra workflow inputs and jobs, a sibling mount — is its own. A repo
that must deviate INSIDE a block, or on a file without blocks, lists paths and
a non-empty reason under policy.template_custom in civikitchen.yaml.

Typical flow:
  civix generate:module org.example.myext
  /path/to/civikitchen/scaffold/ckinit.php org.example.myext
TXT);
  exit($status);
}

$force = FALSE;
$mode = 'seed';
$positionals = [];
foreach (array_slice($argv, 1) as $arg) {
  if ($arg === '--force') {
    $force = TRUE;
  }
  elseif ($arg === '--update' || $arg === '--check') {
    if ($mode !== 'seed') {
      fwrite(STDERR, "ckinit: --update and --check are mutually exclusive\n");
      usage();
    }
    $mode = substr($arg, 2);
  }
  elseif ($arg === '-h' || $arg === '--help') {
    usage(0);
  }
  elseif (str_starts_with($arg, '-')) {
    fwrite(STDERR, "ckinit: unknown option: {$arg}\n");
    usage();
  }
  else {
    $positionals[] = $arg;
  }
}

if ($force && $mode !== 'seed') {
  // --force means "overwrite files the repo owns"; combined with --update it
  // would silently clobber composer.json and friends. Say what you mean.
  fwrite(STDERR, "ckinit: --force only applies to seeding, not --{$mode}\n");
  usage();
}

if (count($positionals) !== 1) {
  usage();
}

$target = realpath($positionals[0]);
if ($target === FALSE || !is_dir($target)) {
  fwrite(STDERR, "ckinit: target directory does not exist: {$positionals[0]}\n");
  exit(2);
}

$infoPath = $target . '/info.xml';
if (!is_file($infoPath)) {
  fwrite(STDERR, "ckinit: target is not a CiviCRM extension (missing info.xml): {$target}\n");
  exit(2);
}

$previous = libxml_use_internal_errors(TRUE);
$xml = simplexml_load_file($infoPath);
libxml_use_internal_errors($previous);
if ($xml === FALSE) {
  fwrite(STDERR, "ckinit: cannot parse {$infoPath}\n");
  exit(2);
}

$extensionFile = trim((string) $xml->file);
if ($extensionFile === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $extensionFile) !== 1) {
  fwrite(STDERR, "ckinit: info.xml has an invalid or missing <file> value\n");
  exit(2);
}
$scenarioName = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $extensionFile));
if (preg_match('/^[a-z]/', $scenarioName) !== 1) $scenarioName = 'extension-' . $scenarioName;

// Composer vendor from the reverse-domain key: `org.example.myext` -> `example`.
$keySegments = explode('.', trim((string) $xml['key']));
$vendor = count($keySegments) >= 3 ? $keySegments[count($keySegments) - 2] : '';
if (preg_match('/^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$/', $vendor) !== 1) {
  $vendor = 'example';
}

// Template files the repo has declared custom, from civikitchen.yaml (same KEY=VALUE
// format ckconform reads; first occurrence wins). The reason after ' -- ' is
// mandatory — an unexplained exception is indistinguishable from a stale one.
// For a MANAGED file, custom means "the content is this repo's own"; for a
// SEEDED file it means the repo owns the file's whole existence — including
// not having it (a repo with no PHP test suite and a declared tests policy
// has no business carrying a phpunit.xml.dist, and --update must not keep
// reseeding one). Names are validated against the full template inventory, so
// a typo still fails loudly instead of disabling nothing.
//
// The parser is ckconform's, required straight out of the checkout this script
// runs from: a private copy of the same loop is how civikitchen.yaml came to have
// seven readers that disagreed on whitespace, comments and the reason suffix.
// ckinit runs on a bare runner with no image, so it cannot shell out to
// `ckconform --policy-env` the way the ck* tools do — but it is PHP, so it can
// use the very class that command uses.
require_once $yamlAutoload;
require_once dirname(__DIR__) . '/toolbelt/ckconform/src/Policy.php';

$custom = [];
$legacyPolicy = $target . '/' . \CiviKitchen\Ckconform\Policy::LEGACY_FILE;
if (is_file($legacyPolicy)) {
  fwrite(STDERR, "ckinit: legacy policy file is no longer supported; migrate it to civikitchen.yaml\n");
  exit(2);
}
$policyRaw = is_file($target . '/civikitchen.yaml') ? file_get_contents($target . '/civikitchen.yaml') : FALSE;
if (is_string($policyRaw)) {
  $declared = \CiviKitchen\Ckconform\Policy::parse($policyRaw)['template_custom'] ?? [];
  // First occurrence wins, as before; ckconform's policy-key check is what
  // reports a second line that would silently do nothing.
  foreach (array_slice($declared, 0, 1) as $value) {
    if (preg_match('/\s--\s\S/', $value) !== 1) {
      fwrite(STDERR, "ckinit: policy.template_custom in civikitchen.yaml needs paths and a reason\n");
      exit(2);
    }
    $value = (string) preg_replace('/\s--\s.*$/', '', $value);
    foreach (explode(',', $value) as $item) {
      $item = trim($item);
      if ($item === '') {
        continue;
      }
      if (!in_array($item, MANAGED_FILES, TRUE) && !in_array($item, SEEDED_FILES, TRUE)) {
        fwrite(STDERR, "ckinit: template_custom lists '{$item}', which is not a template file.\n");
        fwrite(STDERR, "Template files:\n  " . implode("\n  ", array_merge(MANAGED_FILES, SEEDED_FILES)) . "\n");
        exit(2);
      }
      $custom[$item] = TRUE;
    }
  }
}

// The Renovate preset the managed renovate.json extends. An organisation
// sets it once in the CK_DEFAULT_CONFIG file rather than per repo, which is
// why this goes through the layered view; the repo file can still override.
try {
  $effective = \CiviKitchen\Ckconform\Policy::effective(is_string($policyRaw) ? $policyRaw : NULL);
}
catch (\RuntimeException $e) {
  fwrite(STDERR, "ckinit: {$e->getMessage()}\n");
  exit(2);
}
$renovatePreset = \CiviKitchen\Ckconform\Policy::stripReason($effective['renovate_preset'][0] ?? 'config:recommended');
if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.:/>@-]*$#', $renovatePreset) !== 1) {
  fwrite(STDERR, "ckinit: renovate_preset '{$renovatePreset}' is not a Renovate preset name (e.g. github>org/renovate)\n");
  exit(2);
}

$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::SELF_FIRST,
);

$files = [];
$conflicts = [];
foreach ($iterator as $item) {
  if ($item->isDir()) {
    continue;
  }
  $relative = substr($item->getPathname(), strlen($templateDir) + 1);
  $destination = $target . '/' . $relative;
  $content = file_get_contents($item->getPathname());
  if ($content === FALSE) {
    fwrite(STDERR, "ckinit: cannot read template file: {$relative}\n");
    exit(1);
  }
  $files[] = [
    $destination,
    $relative,
    $item->getPerms() & 0777,
    str_replace(
      ['__EXTKEY__', '__EXTENSION_KEY__', '__SCENARIO_NAME__', '__VENDOR__', '__RENOVATE_PRESET__'],
      [$extensionFile, trim((string) $xml['key']), $scenarioName, $vendor, $renovatePreset],
      $content,
    ),
  ];
  if ($mode === 'seed' && (file_exists($destination) || is_link($destination)) && !$force) {
    $conflicts[] = $relative;
  }
  for ($check = dirname($destination); str_starts_with($check, $target . '/'); $check = dirname($check)) {
    if (is_link($check)) {
      fwrite(STDERR, "ckinit: refusing destination below symlink: {$check}\n");
      exit(1);
    }
  }
}
usort($files, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));

// The two lists must be an exact, disjoint inventory of the template. Checked
// on every run so the mismatch surfaces on the developer machine that added
// the file, not months later in some repo's CI.
$inventory = array_column($files, 1);
$unclassified = array_diff($inventory, MANAGED_FILES, SEEDED_FILES);
$stale = array_diff(array_merge(MANAGED_FILES, SEEDED_FILES), $inventory);
$overlap = array_intersect(MANAGED_FILES, SEEDED_FILES);
// An optional file that is not seeded would be a file nothing ever creates.
$orphanOptional = array_diff(OPTIONAL_FILES, SEEDED_FILES);
if ($unclassified !== [] || $stale !== [] || $overlap !== [] || $orphanOptional !== []) {
  foreach ($unclassified as $relative) {
    fwrite(STDERR, "ckinit: template file not classified — add to MANAGED_FILES or SEEDED_FILES: {$relative}\n");
  }
  foreach ($stale as $relative) {
    fwrite(STDERR, "ckinit: listed file missing from the template: {$relative}\n");
  }
  foreach ($overlap as $relative) {
    fwrite(STDERR, "ckinit: file listed as both managed and seeded: {$relative}\n");
  }
  foreach ($orphanOptional as $relative) {
    fwrite(STDERR, "ckinit: optional file is not in SEEDED_FILES: {$relative}\n");
  }
  exit(2);
}

if ($conflicts !== []) {
  fwrite(STDERR, "ckinit: refusing to overwrite existing files:\n");
  foreach ($conflicts as $relative) {
    fwrite(STDERR, "  {$relative}\n");
  }
  fwrite(STDERR, "Re-run with --force only after reviewing these files,\n");
  fwrite(STDERR, "or --update to refresh just the template-managed files.\n");
  exit(1);
}

/**
 * The managed blocks of a file, in order: name => text including both marker
 * lines. A file without markers is whole-file managed. Unbalanced or repeated
 * markers are a template or repo error, never silently a smaller block.
 *
 * @return array<string, string>
 */
function managedBlocks(string $content, string $relative): array {
  $blocks = [];
  $open = NULL;
  $buffer = '';
  foreach (preg_split('/(?<=\n)/', $content) ?: [] as $line) {
    if (preg_match('/^\s*#\s*(BEGIN|END) CIVIKITCHEN MANAGED (\S+)\s*$/', $line, $m) === 1) {
      if ($m[1] === 'BEGIN') {
        if ($open !== NULL || isset($blocks[$m[2]])) {
          fwrite(STDERR, "ckinit: {$relative}: managed block '{$m[2]}' opened twice or inside '{$open}'\n");
          exit(1);
        }
        $open = $m[2];
        $buffer = $line;
        continue;
      }
      if ($open !== $m[2]) {
        fwrite(STDERR, "ckinit: {$relative}: END of managed block '{$m[2]}' without its BEGIN\n");
        exit(1);
      }
      $blocks[$open] = $buffer . $line;
      $open = NULL;
      continue;
    }
    if ($open !== NULL) {
      $buffer .= $line;
    }
  }
  if ($open !== NULL) {
    fwrite(STDERR, "ckinit: {$relative}: managed block '{$open}' is never closed\n");
    exit(1);
  }
  return $blocks;
}

/**
 * The repo file with every template block's text swapped in, or NULL when
 * the repo's blocks do not match the template's set (a block missing, an
 * unknown one, or a different order) — that needs a person, not a rewrite.
 */
function spliceBlocks(string $repoContent, array $templateBlocks, string $relative): ?string {
  $repoBlocks = managedBlocks($repoContent, $relative);
  if (array_keys($repoBlocks) !== array_keys($templateBlocks)) {
    return NULL;
  }
  $out = $repoContent;
  foreach ($repoBlocks as $name => $text) {
    $at = strpos($out, $text);
    if ($at === FALSE) {
      return NULL;
    }
    $out = substr($out, 0, $at) . $templateBlocks[$name] . substr($out, $at + strlen($text));
  }
  return $out;
}

function writeRendered(string $destination, string $relative, int $perms, string $content): void {
  $parent = dirname($destination);
  if (!is_dir($parent) && !mkdir($parent, 0775, TRUE) && !is_dir($parent)) {
    fwrite(STDERR, "ckinit: cannot create directory: {$parent}\n");
    exit(1);
  }
  $temporary = tempnam($parent, '.ckinit-');
  if ($temporary === FALSE || file_put_contents($temporary, $content) === FALSE) {
    fwrite(STDERR, "ckinit: cannot write: {$destination}\n");
    exit(1);
  }
  if (!chmod($temporary, $perms)) {
    @unlink($temporary);
    fwrite(STDERR, "ckinit: cannot set mode on: {$destination}\n");
    exit(1);
  }
  if (!rename($temporary, $destination)) {
    @unlink($temporary);
    fwrite(STDERR, "ckinit: cannot replace: {$destination}\n");
    exit(1);
  }
}

/** A destination we may only replace if it is an ordinary file (or absent). */
function assertRegular(string $destination, string $relative): void {
  if ((file_exists($destination) || is_link($destination))
    && (is_link($destination) || !is_file($destination))) {
    fwrite(STDERR, "ckinit: refusing non-regular destination: {$relative}\n");
    exit(1);
  }
}

if ($mode === 'seed') {
  // All-or-nothing: refuse strange destinations before the first write.
  foreach ($files as [$destination, $relative]) {
    assertRegular($destination, $relative);
  }
  foreach ($files as [$destination, $relative, $perms, $content]) {
    writeRendered($destination, $relative, $perms, $content);
    fwrite(STDOUT, "created {$relative}\n");
  }
  fwrite(STDOUT, "\nCiviKitchen tooling installed for {$extensionFile}.\n");
  fwrite(STDOUT, "Next: review composer.json and .docker/, then run cklint --all && ckconform.\n");
  exit(0);
}

// --update / --check: managed files converge on the template, missing files
// (managed or seeded) count, seeded files the repo edited are its business.
$drifted = [];
$missing = [];
foreach ($files as [$destination, $relative, $perms, $content]) {
  $managed = in_array($relative, MANAGED_FILES, TRUE);
  if (isset($custom[$relative])) {
    fwrite(STDOUT, "custom    {$relative} (civikitchen.yaml template_custom)\n");
    continue;
  }
  assertRegular($destination, $relative);
  if (!is_file($destination)) {
    if (in_array($relative, OPTIONAL_FILES, TRUE)) {
      fwrite(STDOUT, "optional  {$relative} (absent — opt-in)\n");
      continue;
    }
    $missing[] = $relative;
    if ($mode === 'update') {
      writeRendered($destination, $relative, $perms, $content);
      fwrite(STDOUT, "created   {$relative}\n");
    }
    else {
      fwrite(STDOUT, "missing   {$relative}\n");
    }
    continue;
  }
  // Content plus the executable bit — the one mode bit git actually tracks,
  // so comparing full permissions would drift with the checkout umask.
  $sameExec = (($perms & 0111) !== 0) === ((fileperms($destination) & 0111) !== 0);
  $existing = (string) file_get_contents($destination);
  if (!$managed || ($existing === $content && $sameExec)) {
    continue;
  }
  // Block-managed: the repo's version converges block by block; a repo file
  // that predates the markers is whole-file drift and gets the template.
  $blocks = managedBlocks($content, $relative);
  $rendered = $content;
  if ($blocks !== [] && managedBlocks($existing, $relative) !== []) {
    $rendered = spliceBlocks($existing, $blocks, $relative);
    if ($rendered === NULL) {
      $drifted[] = $relative;
      fwrite(STDOUT, "drifted   {$relative} (managed blocks do not match the template's: "
        . implode(', ', array_keys($blocks)) . " — align the markers by hand)\n");
      continue;
    }
    if ($rendered === $existing && $sameExec) {
      continue;
    }
  }
  $drifted[] = $relative;
  if ($mode === 'update') {
    writeRendered($destination, $relative, $perms, $rendered);
    fwrite(STDOUT, "updated   {$relative}\n");
  }
  else {
    fwrite(STDOUT, "drifted   {$relative}\n");
  }
}

if ($mode === 'update') {
  if ($drifted === [] && $missing === []) {
    fwrite(STDOUT, "Template files are up to date for {$extensionFile}.\n");
  }
  else {
    fwrite(STDOUT, "\nRefreshed " . count($drifted) . " managed / created " . count($missing)
      . " missing file(s) for {$extensionFile}. Review with git diff before committing.\n");
  }
  exit(0);
}

if ($drifted === [] && $missing === []) {
  fwrite(STDOUT, "Template files are up to date for {$extensionFile}.\n");
  exit(0);
}
fwrite(STDERR, "\nckinit: " . count($drifted) . " drifted / " . count($missing)
  . " missing template file(s). Run scaffold/ckinit.php --update <dir> to refresh,\n"
  . "or declare a deliberate deviation under policy.template_custom in civikitchen.yaml\n");
exit(1);
