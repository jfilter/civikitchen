<?php

declare(strict_types = 1);

/**
 * The bundled ckdeps config decides which "unknown" classes are core's rather
 * than the extension's. That decision is one regex, and a typo in it either
 * drowns a repo in false findings or silently stops reporting a real one.
 *
 * The analyser itself is installed into the image, not into this repo, so the
 * config is included against stubs and the captured pattern is asserted
 * directly.
 */

namespace ShipMonk\ComposerDependencyAnalyser\Config;

class Configuration {

  public static ?string $classesRegex = NULL;

  public static ?string $functionsRegex = NULL;

  public function ignoreUnknownClassesRegex(string $regex): self {
    self::$classesRegex = $regex;
    return $this;
  }

  public function ignoreUnknownFunctionsRegex(string $regex): self {
    self::$functionsRegex = $regex;
    return $this;
  }

  /**
   * Every other builder call is irrelevant here and returns the same object.
   *
   * @param array<int, mixed> $arguments
   */
  public function __call(string $name, array $arguments): self {
    return $this;
  }

}

class ErrorType {

  public const SHADOW_DEPENDENCY = 'shadow';

  public const UNUSED_DEPENDENCY = 'unused';

}

namespace CiviKitchen\Tests;

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

$root = dirname(__DIR__, 2);
require $root . '/toolbelt/lib/composer-deps.php';

$classes = Configuration::$classesRegex;
$functions = Configuration::$functionsRegex;
if (!is_string($classes) || !is_string($functions)) {
  fwrite(STDERR, "composer-deps.php did not set both ignore patterns\n");
  exit(1);
}

/**
 * Expected: 1 = core provides it, so an extension must not declare it.
 * 0 = not core's, so a missing declaration stays a finding.
 */
$expected = [
  'CRM_Core_Error' => 1,
  'Civi' => 1,
  'Civi\Api4\Contact' => 1,
  'CiviMix\Schema\AutomaticUpgrader' => 1,
  'GuzzleHttp\Client' => 1,
  'Psr\Log\LoggerInterface' => 1,
  // Core's own composer.json ships these; an extension decorating the
  // container or subscribing to an event types against them.
  'Symfony\Component\DependencyInjection\ContainerBuilder' => 1,
  'Symfony\Component\DependencyInjection\Definition' => 1,
  'Symfony\Component\DependencyInjection\Reference' => 1,
  'Symfony\Component\EventDispatcher\EventDispatcher' => 1,
  'Symfony\Component\Config\FileLocator' => 1,
  'Symfony\Contracts\EventDispatcher\Event' => 1,
  // Core does NOT ship these, so an extension that uses one has to declare it
  // and the analyser has to keep saying so.
  'Symfony\Component\Serializer\Serializer' => 0,
  'Symfony\Component\Mailer\Mailer' => 0,
  'Sentry\Client' => 0,
  'League\Csv\Reader' => 0,
];

$failures = 0;
foreach ($expected as $class => $want) {
  $got = preg_match($classes, $class);
  if ($got === FALSE) {
    fwrite(STDERR, "the class pattern is not a valid regex\n");
    exit(1);
  }
  if ($got !== $want) {
    fwrite(STDERR, sprintf("%s: expected match=%d, got %d\n", $class, $want, $got));
    $failures++;
  }
}

foreach (['civicrm_api3' => 1, 'civix_something' => 1, 'array_map' => 0] as $function => $want) {
  $got = preg_match($functions, $function);
  if ($got !== $want) {
    fwrite(STDERR, sprintf("%s(): expected match=%d, got %d\n", $function, $want, $got));
    $failures++;
  }
}

if ($failures > 0) {
  fwrite(STDERR, sprintf("%d ckdeps ignore-pattern case(s) failed\n", $failures));
  exit(1);
}

echo "ok   ckdeps ignore patterns cover core-provided classes only\n";
