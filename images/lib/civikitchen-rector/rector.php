<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\Api3ToApi4AssistRector;
use CiviKitchen\Rector\Rules\Api3ToApi4OopAssistRector;
use CiviKitchen\Rector\Rules\Api4ArrayToOopRector;
use CiviKitchen\Rector\Rules\CrmCoreErrorFatalToExceptionRector;
use CiviKitchen\Rector\Rules\CrmUtilsArrayValueToCoalesceRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

/**
 * CiviKitchen's default rector config, used by `ckmodernize` when an extension
 * ships no rector.php of its own. It combines:
 *   - rector's OFF-THE-SHELF PHP-version migration sets ("PHP 7 -> 8" and up),
 *   - off-the-shelf code-quality / dead-code / early-return sets,
 *   - CiviKitchen's own CiviCRM footgun rules (the deprecations cklint bans).
 *
 * Target PHP for the upgrade sets is CK_PHP_VERSION (default 8.1 = CiviCRM floor).
 */

$target = getenv('CK_PHP_VERSION') ?: '8.1';
[$phpVersion, $phpSetFlag] = match ($target) {
  '8.0' => [PhpVersion::PHP_80, 'php80'],
  '8.2' => [PhpVersion::PHP_82, 'php82'],
  '8.3' => [PhpVersion::PHP_83, 'php83'],
  '8.4' => [PhpVersion::PHP_84, 'php84'],
  default => [PhpVersion::PHP_81, 'php81'],
};

$rules = [
  CrmUtilsArrayValueToCoalesceRector::class,
  CrmCoreErrorFatalToExceptionRector::class,
];
// API style. Safe by DEFAULT: restyle existing api4 array-form calls to the OO
// builder (same version, same semantics). The risky api3 -> api4 migration is
// opt-in via `ckmodernize --api`. `--api=array` instead keeps the array style
// (migrate api3 to the array form, leave api4 arrays as-is).
$apiStyle = getenv('CK_API_MIGRATE');
if ($apiStyle === 'array') {
  $rules[] = Api3ToApi4AssistRector::class;
}
else {
  $rules[] = Api4ArrayToOopRector::class;
  if ($apiStyle === 'oop') {
    $rules[] = Api3ToApi4OopAssistRector::class;
  }
}

return RectorConfig::configure()
  // PHP version migration — rector-maintained, we write none of it.
  ->withPhpVersion($phpVersion)
  ->withPhpSets(...[$phpSetFlag => TRUE])
  // Generic modernization — also off-the-shelf.
  ->withSets([
    SetList::CODE_QUALITY,
    SetList::DEAD_CODE,
    SetList::EARLY_RETURN,
  ])
  // The only custom part: CiviCRM footgun fixes (no package ships these).
  ->withRules($rules)
  // Never rewrite generated / vendored code.
  ->withSkip([
    '*/vendor/*',
    '*/node_modules/*',
    '*.civix.php',
    '*/CRM/*/DAO/*',
    '*/mixin/*',
  ]);
