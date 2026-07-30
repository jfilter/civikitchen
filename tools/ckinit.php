#!/usr/bin/env php
<?php
declare(strict_types=1);

$templateDir = dirname(__DIR__) . '/template/extension';

function usage(int $status = 2): never {
  $stream = $status === 0 ? STDOUT : STDERR;
  fwrite($stream, <<<'TXT'
ckinit — add the CiviKitchen development standard to a civix extension.

Usage:
  tools/ckinit.php [--force] <extension-directory>

The target must contain info.xml. Files from template/extension are copied
recursively; __EXTKEY__ is replaced with info.xml's <file> value and
__VENDOR__ with the vendor segment of the extension key. Existing
files are preserved unless --force is given.

Typical flow:
  civix generate:module org.example.myext
  /path/to/civikitchen/tools/ckinit.php org.example.myext
TXT);
  exit($status);
}

$force = FALSE;
$positionals = [];
foreach (array_slice($argv, 1) as $arg) {
  if ($arg === '--force') {
    $force = TRUE;
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
  if ((file_exists($destination) || is_link($destination)) && !$force) {
    $conflicts[] = $relative;
  }
  elseif ((file_exists($destination) || is_link($destination))
    && (is_link($destination) || !is_file($destination))) {
    fwrite(STDERR, "ckinit: refusing non-regular destination even with --force: {$relative}\n");
    exit(1);
  }
  for ($check = dirname($destination); str_starts_with($check, $target . '/'); $check = dirname($check)) {
    if (is_link($check)) {
      fwrite(STDERR, "ckinit: refusing destination below symlink: {$check}\n");
      exit(1);
    }
  }
}

if ($conflicts !== []) {
  fwrite(STDERR, "ckinit: refusing to overwrite existing files:\n");
  foreach ($conflicts as $relative) {
    fwrite(STDERR, "  {$relative}\n");
  }
  fwrite(STDERR, "Re-run with --force only after reviewing these files.\n");
  exit(1);
}

foreach ($files as [$destination, $relative, $mode, $content]) {
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
  chmod($temporary, $mode);
  if (!rename($temporary, $destination)) {
    @unlink($temporary);
    fwrite(STDERR, "ckinit: cannot replace: {$destination}\n");
    exit(1);
  }
  fwrite(STDOUT, "created {$relative}\n");
}

fwrite(STDOUT, "\nCiviKitchen tooling installed for {$extensionFile}.\n");
fwrite(STDOUT, "Next: review composer.json and .docker/, then run cklint --all && ckconform.\n");
