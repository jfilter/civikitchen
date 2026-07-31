#!/usr/bin/env php
<?php
declare(strict_types=1);

$templateDir = dirname(__DIR__) . '/template/extension';

/**
 * Template files civikitchen OWNS: identical in every conforming repo, safe to
 * overwrite on --update and compared on --check. Everything else in the
 * template is seeded once (composer.json, phpstan.neon.dist, the dev compose
 * file, .gitignore) and then belongs to the extension — repos edit those, so
 * ckinit never touches them again.
 *
 * A repo that must deviate on a managed file declares it in its .ckconform:
 *
 *   template_custom=.docker/docker-compose.ci.yml -- sibling mounts for e2e
 */
const MANAGED_FILES = [
  '.gitattributes',
  '.github/workflows/ci.yml',
  '.docker/docker-compose.ci.yml',
  '.docker/db-init/01-grants.sql',
  '.docker/init.d/README.md',
  'phpcs.xml.dist',
  'phpstanBootstrap.php',
  'tests/phpunit/bootstrap.php',
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
  '.docker/docker-compose.yml',
  'composer.json',
  'phpstan.neon.dist',
  'phpunit.xml.dist',
];

function usage(int $status = 2): never {
  $stream = $status === 0 ? STDOUT : STDERR;
  fwrite($stream, <<<'TXT'
ckinit — add or refresh the CiviKitchen development standard in a civix extension.

Usage:
  tools/ckinit.php [--force] <extension-directory>    seed the template
  tools/ckinit.php --update <extension-directory>     refresh managed files
  tools/ckinit.php --check <extension-directory>      report drift, exit 1 on any

The target must contain info.xml. Files from template/extension are copied
recursively; __EXTKEY__ is replaced with info.xml's <file> value and
__VENDOR__ with the vendor segment of the extension key.

Seeding preserves existing files unless --force is given. --update rewrites
only the MANAGED files (CI caller, test bootstraps, CI compose stack, phpcs
layer — the ones meant to be identical everywhere) and creates whatever is
missing; seeded files the repo has edited (composer.json, phpstan.neon.dist,
dev compose, .gitignore) are never touched. --check is the dry twin for CI.

A repo that must deviate on a managed file lists it in .ckconform:
  template_custom=<file>[,<file>...] -- <reason>

Typical flow:
  civix generate:module org.example.myext
  /path/to/civikitchen/tools/ckinit.php org.example.myext
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

// Composer vendor from the reverse-domain key: `org.example.myext` -> `example`.
$keySegments = explode('.', trim((string) $xml['key']));
$vendor = count($keySegments) >= 3 ? $keySegments[count($keySegments) - 2] : '';
if (preg_match('/^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$/', $vendor) !== 1) {
  $vendor = 'example';
}

// Managed files the repo has declared custom, from .ckconform (same KEY=VALUE
// format ckconform reads; first occurrence wins). The reason after ' -- ' is
// mandatory — an unexplained exception is indistinguishable from a stale one —
// and only MANAGED files may be listed: exempting a seeded file would exempt
// its very existence, and a typo would otherwise disable nothing, silently.
$custom = [];
$policyRaw = is_file($target . '/.ckconform') ? file_get_contents($target . '/.ckconform') : FALSE;
if (is_string($policyRaw)) {
  foreach (explode("\n", $policyRaw) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
      continue;
    }
    [$key, $value] = explode('=', $line, 2);
    if (trim($key) !== 'template_custom') {
      continue;
    }
    $value = trim($value);
    if (preg_match('/\s--\s\S/', $value) !== 1) {
      fwrite(STDERR, "ckinit: template_custom in .ckconform needs a reason: template_custom=<file>,... -- <reason>\n");
      exit(2);
    }
    $value = (string) preg_replace('/\s--\s.*$/', '', $value);
    foreach (explode(',', $value) as $item) {
      $item = trim($item);
      if ($item === '') {
        continue;
      }
      if (!in_array($item, MANAGED_FILES, TRUE)) {
        fwrite(STDERR, "ckinit: template_custom lists '{$item}', which is not a template-managed file.\n");
        fwrite(STDERR, "Managed files:\n  " . implode("\n  ", MANAGED_FILES) . "\n");
        exit(2);
      }
      $custom[$item] = TRUE;
    }
    break;
  }
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
    str_replace(['__EXTKEY__', '__VENDOR__'], [$extensionFile, $vendor], $content),
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
if ($unclassified !== [] || $stale !== [] || $overlap !== []) {
  foreach ($unclassified as $relative) {
    fwrite(STDERR, "ckinit: template file not classified — add to MANAGED_FILES or SEEDED_FILES: {$relative}\n");
  }
  foreach ($stale as $relative) {
    fwrite(STDERR, "ckinit: listed file missing from the template: {$relative}\n");
  }
  foreach ($overlap as $relative) {
    fwrite(STDERR, "ckinit: file listed as both managed and seeded: {$relative}\n");
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
    fwrite(STDOUT, "custom    {$relative} (.ckconform template_custom)\n");
    continue;
  }
  assertRegular($destination, $relative);
  if (!is_file($destination)) {
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
  if (!$managed || (file_get_contents($destination) === $content && $sameExec)) {
    continue;
  }
  $drifted[] = $relative;
  if ($mode === 'update') {
    writeRendered($destination, $relative, $perms, $content);
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
  . " missing template file(s). Run tools/ckinit.php --update <dir> to refresh,\n"
  . "or declare a deliberate deviation in .ckconform: template_custom=<file> -- <reason>\n");
exit(1);
