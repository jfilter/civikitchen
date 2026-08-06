<?php
/**
 * Toolbelt/Dockerfile parity check: every toolbelt component in the repo must
 * be COPY'd into an image, or a new tool ships in git and silently never
 * reaches the images eleven repos run their CI on.
 *
 * Usage:
 *   php toolbelt-parity.php --toolbelt <dir> --dockerfile <file> [--allow <component>]...
 *
 * Components are toolbelt-relative paths: every file in bin/ and lib/, every
 * other top-level entry (dirs like phpcs, files like versions.env). A
 * component is covered when a COPY source equals it, is an ancestor of it
 * (dir copy), or lies under it (subpath copy like phpcs/CiviKitchen).
 * --allow names a deliberate per-image omission. Exit 1 lists what is missing.
 */

/**
 * The one Dockerfile parser: COPY source paths, continuations joined,
 * comments and --flags dropped, the last argument (the destination) dropped.
 *
 * @return list<string>
 */
function ck_dockerfile_copy_sources(string $dockerfile): array {
  $raw = file_get_contents($dockerfile);
  if ($raw === FALSE) {
    fwrite(STDERR, "cannot read $dockerfile\n");
    exit(2);
  }
  // A continuation may be followed by comment lines, which BuildKit drops
  // before joining — remove comments first, then join.
  $lines = [];
  foreach (preg_split('/\R/', $raw) as $line) {
    if (preg_match('/^\s*#/', $line)) {
      continue;
    }
    $lines[] = $line;
  }
  $joined = preg_replace('/\\\\\n/', ' ', implode("\n", $lines));

  $sources = [];
  foreach (preg_split('/\R/', $joined) as $line) {
    if (!preg_match('/^\s*COPY\s+(.*)$/i', $line, $m)) {
      continue;
    }
    $args = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
    // Flags (--from, --chown, --chmod, ...) precede the paths.
    $args = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
    array_pop($args); // destination
    foreach ($args as $src) {
      $sources[] = $src;
    }
  }
  return $sources;
}

/** @return list<string> toolbelt-relative component paths */
function ck_toolbelt_components(string $toolbelt): array {
  $components = [];
  foreach (scandir($toolbelt) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }
    // bin/ and lib/ are per-file: a dir-level match would hide a new tool.
    if (($entry === 'bin' || $entry === 'lib') && is_dir("$toolbelt/$entry")) {
      foreach (scandir("$toolbelt/$entry") ?: [] as $file) {
        if ($file !== '.' && $file !== '..') {
          $components[] = "$entry/$file";
        }
      }
    }
    else {
      $components[] = $entry;
    }
  }
  sort($components);
  return $components;
}

$toolbelt = NULL;
$dockerfile = NULL;
$allow = [];
for ($i = 1; $i < $argc; $i++) {
  switch ($argv[$i]) {
    case '--toolbelt':
      $toolbelt = $argv[++$i] ?? NULL;
      break;

    case '--dockerfile':
      $dockerfile = $argv[++$i] ?? NULL;
      break;

    case '--allow':
      $allow[] = $argv[++$i] ?? '';
      break;

    default:
      fwrite(STDERR, "unknown argument: {$argv[$i]}\n");
      exit(2);
  }
}
if ($toolbelt === NULL || $dockerfile === NULL || !is_dir($toolbelt)) {
  fwrite(STDERR, "usage: php toolbelt-parity.php --toolbelt <dir> --dockerfile <file> [--allow <component>]...\n");
  exit(2);
}

$prefix = rtrim(basename($toolbelt), '/');
$copied = [];
foreach (ck_dockerfile_copy_sources($dockerfile) as $src) {
  if ($src === $prefix || str_starts_with($src, "$prefix/")) {
    $copied[] = $src === $prefix ? '' : substr($src, strlen($prefix) + 1);
  }
}

$covers = function (string $component) use ($copied): bool {
  foreach ($copied as $src) {
    if ($src === '' || $src === $component
      || str_starts_with($component, "$src/")
      || str_starts_with($src, "$component/")) {
      return TRUE;
    }
  }
  return FALSE;
};

$missing = [];
$stale = [];
foreach (ck_toolbelt_components($toolbelt) as $component) {
  $allowed = in_array($component, $allow, TRUE);
  if ($covers($component)) {
    if ($allowed) {
      $stale[] = $component;
    }
  }
  elseif (!$allowed) {
    $missing[] = $component;
  }
}
foreach ($allow as $a) {
  if (!in_array($a, ck_toolbelt_components($toolbelt), TRUE)) {
    $stale[] = $a;
  }
}

$rc = 0;
if ($missing) {
  fwrite(STDERR, "$dockerfile misses toolbelt components (add a COPY, or an --allow with a reason in the test):\n");
  foreach ($missing as $m) {
    fwrite(STDERR, "  - $m\n");
  }
  $rc = 1;
}
if ($stale) {
  fwrite(STDERR, "$dockerfile: stale --allow entries (the component is copied or gone — drop the exception):\n");
  foreach (array_unique($stale) as $s) {
    fwrite(STDERR, "  - $s\n");
  }
  $rc = 1;
}
if ($rc === 0) {
  echo "$dockerfile: toolbelt parity OK\n";
}
exit($rc);
