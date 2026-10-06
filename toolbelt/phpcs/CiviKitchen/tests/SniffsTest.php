<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CiviKitchen sniffs, run against the REAL phpcs binary
 * (the one the image ships): fixtures with known violations must produce
 * exactly the expected sniff codes on the expected lines, and the
 * modern-counterpart fixture must produce zero findings — every line in it
 * is a near-miss a sloppy token matcher would flag.
 *
 * Runs inside a civikitchen image (phpunit /opt/civikitchen/toolbelt/phpcs/CiviKitchen/tests),
 * which registers the standard and PHPCSUtils. The CiviKitchen\Util helpers
 * autoload from the registered standard path, also under the fixture rulesets.
 */
final class SniffsTest extends TestCase {

  private const SNIFFS = 'CiviKitchen.I18n.UseExtensionTs,'
    . 'CiviKitchen.Api.NoRequiredOnExternalAction,'
    . 'CiviKitchen.Api.NoGenericVarOnActionParam,'
    . 'CiviKitchen.Security.NoUnsafeUnserialize,'
    . 'CiviKitchen.Tests.NoTautologicalAssertion,'
    . 'CiviKitchen.Extension.UseMixinsForStandardHooks,'
    . 'CiviKitchen.Files.MaxFileLength';

  /**
   * Whole-file / call-site sniffs, exercised on their own fixtures. Includes
   * the third-party sniff this standard configures: the wiring is ours to get
   * wrong (registration, the no-spaces property), so it is ours to test.
   */
  private const PHP8_SNIFFS = 'SlevomatCodingStandard.TypeHints.DeclareStrictTypes,CiviKitchen.Modern.NameBooleanArguments';

  /**
   * Run phpcs over one fixture, restricted to the CiviKitchen sniffs, and
   * return the [line => [sniff codes]] map from the JSON report.
   *
   * The CiviKitchen ruleset references the Drupal standard, so phpcs needs
   * civicrm/coder available either way (the image registers both). When the
   * CiviKitchen NAME is not registered (bare checkout), fall back to this
   * tree's ruleset.xml by path.
   *
   * @return array<int, list<string>>
   */
  private function phpcs(string $fixture, ?string $standard = NULL, ?string $sniffs = NULL): array {
    $fixturePath = __DIR__ . '/fixtures/' . $fixture;
    self::assertFileExists($fixturePath);

    if ($standard === NULL) {
      exec('phpcs -i 2>/dev/null', $registered);
      $standard = str_contains(implode(' ', $registered), 'CiviKitchen')
        ? 'CiviKitchen'
        : dirname(__DIR__) . '/ruleset.xml';
    }

    $cmd = sprintf(
      'phpcs -q --standard=%s --sniffs=%s --report=json %s 2>/dev/null',
      escapeshellarg($standard),
      escapeshellarg($sniffs ?? self::SNIFFS),
      escapeshellarg($fixturePath)
    );
    exec($cmd, $outputLines, $exitCode);
    $report = json_decode(implode("\n", $outputLines), TRUE);
    self::assertIsArray($report, "phpcs produced no JSON (exit {$exitCode}): " . implode("\n", $outputLines));

    $byLine = [];
    foreach ($report['files'] as $file) {
      foreach ($file['messages'] as $message) {
        $byLine[(int) $message['line']][] = (string) $message['source'];
      }
    }
    ksort($byLine);
    return $byLine;
  }

  /**
   * @param list<int> $lines
   *
   * @return array<int, list<string>>
   */
  private static function on(array $lines, string $code): array {
    return array_fill_keys($lines, [$code]);
  }

  public function testUseExtensionTsFlagsGlobalTsCallsAndCallbacks(): void {
    // Every case spelling, comments before the parenthesis, the first-class
    // callable, and 'ts' as the whole callback argument (positional or named).
    self::assertSame(
      self::on(range(8, 25), 'CiviKitchen.I18n.UseExtensionTs.BareTs'),
      $this->phpcs('BareTs.php')
    );
  }

  public function testUseMixinsForStandardHooksFallsBackToSuffixWithoutInfoXml(): void {
    // No info.xml above the fixture: any _<hook> suffix counts, the helper on
    // line 34 included. alterSettingsMetaData (line 13) is a runtime hook.
    self::assertSame(
      self::on([7, 10, 16, 19, 22, 25, 28, 31, 34], 'CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'),
      $this->phpcs('LegacyMixinHooks.php')
    );
  }

  public function testUseMixinsForStandardHooksMatchesTheInfoXmlPrefix(): void {
    // acme_civicrm_<hook> in any case, also inside the function_exists() guard
    // (line 24). Not: other prefixes, helpers, nested functions and methods.
    self::assertSame(
      self::on([6, 11, 14, 17, 20, 24], 'CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'),
      $this->phpcs('mixin-ext/acme.php')
    );
  }

  public function testUseMixinsForStandardHooksJudgesOnlyGlobalFunctions(): void {
    self::assertSame(
      self::on([13], 'CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'),
      $this->phpcs('mixin-ext/namespaced.php')
    );
  }

  public function testNoGenericVarOnActionParamMirrorsCoresTypeCheck(): void {
    // Flagged: generics, int[], pseudo types, ?string, aliases and object on
    // protected params of classes detected as actions by parent FQN (lines
    // 13-45), namespace + *Action parent (113), a Civi\Api4 parent outside
    // Civi\Api4 (126) and _run() (135). Not flagged: accepted types, shapes,
    // `<` in the description, private/public/_/version, CiviRules actions.
    self::assertSame(
      self::on([13, 18, 23, 28, 33, 38, 113, 126, 135], 'CiviKitchen.Api.NoGenericVarOnActionParam.GenericActionVar'),
      $this->phpcs('GenericActionVar.php')
    );
  }

  public function testNoUnsafeUnserializeRequiresAnAllowedClassesDecision(): void {
    // Flagged (lines 11-27): no options, an empty or allowed_classes-less
    // literal, trailing comma, comment, match body, spread, string callables.
    // Not flagged: allowed_classes given, non-literal options, methods,
    // namespaced functions and 'unserialize' that is not the whole callback.
    self::assertSame(
      self::on(range(11, 27), 'CiviKitchen.Security.NoUnsafeUnserialize.UnsafeUnserialize'),
      $this->phpcs('UnsafeUnserialize.php')
    );
  }

  public function testNoTautologicalAssertionFlagsLiteralOutcomes(): void {
    // Line 23 opens the multi-line static::assertTrue( call.
    $lines = array_merge(range(12, 23), range(26, 35));
    self::assertSame(
      self::on($lines, 'CiviKitchen.Tests.NoTautologicalAssertion.TautologicalAssertion'),
      $this->phpcs('TautologicalAssertion.php')
    );
  }

  public function testModernCounterpartsProduceZeroFindings(): void {
    self::assertSame([], $this->phpcs('CleanModern.php'));
    // The two PHP-8 sniffs run apart from the list above: they would report
    // every fixture's opening tag, and shifting those files' lines to add a
    // declare would move the line numbers the other tests assert.
    self::assertSame([], $this->phpcs('CleanModern.php', NULL, self::PHP8_SNIFFS));
  }

  public function testDeclareStrictTypesIsWiredUpAndAcceptsTheFleetSpacing(): void {
    $findings = $this->phpcs('MissingStrictTypes.php', NULL, self::PHP8_SNIFFS);

    // Reported on line 1: a missing declare is a whole-file fact. The clean
    // fixtures above carry `strict_types = 1` — spaced, the shape ckfmt writes
    // and the only one this standard's spacesCountAroundEqualsSign accepts.
    self::assertSame(
      [1 => ['SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing']],
      $findings
    );
  }

  public function testNameBooleanArgumentsFlagsOnlyBarePositionalLiterals(): void {
    $findings = $this->phpcs('BooleanArgs.php', NULL, self::PHP8_SNIFFS);

    // Flagged: the positional literals, a setter's non-sole argument and a
    // set-prefixed non-setter. Not flagged: in_array's strict flag
    // (ignoreCalls), an already-named argument, a variable, a comparison that
    // merely contains TRUE, an assignment, an array element, a setter's sole
    // argument, and the parameter default in the declaration.
    // Lines 32-34: a method, a constructor and `\TRUE`. Not flagged below
    // them: ignoreCalls in any case, and the value positions of variadics,
    // APIv4 setters, settings and PHPUnit's expected values.
    self::assertSame(self::on([9, 10, 18, 19, 32, 33, 34], 'CiviKitchen.Modern.NameBooleanArguments.UnnamedBoolean'), $findings);
  }

  public function testMaxFileLengthFlagsOnlyFilesOverTheConfiguredCap(): void {
    // Under the generous default cap (1000) a small file is never flagged.
    self::assertSame([], $this->phpcs('LongFile.php'),
      'a short file is well within the default cap');

    // Armed with a low cap (maxLines=10), every over-long file trips on line
    // 1 — a whole-file fact — whatever its first token is.
    $armed = __DIR__ . '/fixtures/max-file-length-ruleset.xml';
    $line1 = [1 => ['CiviKitchen.Files.MaxFileLength.TooLong']];
    self::assertSame($line1, $this->phpcs('LongFile.php', $armed));
    self::assertSame($line1, $this->phpcs('ShortPhp.php', $armed), 'eleven <?php lines');
    self::assertSame($line1, $this->phpcs('TemplateEcho.php', $armed), 'a template opening with <?=');
    self::assertSame($line1, $this->phpcs('TemplateHtml.php', $armed), 'HTML first, <?php on line 15');
  }

  public function testRequiredGuardIsInertWithoutConfiguredExternalActions(): void {
    // The plain CiviKitchen standard configures no externalActions — the
    // guard must never guess which actions are external.
    self::assertSame([], $this->phpcs('RequiredOnIntake.php'));
    self::assertSame([], $this->phpcs('RequiredOnImporter.php'));
  }

  public function testRequiredGuardFlagsOnlyTheConfiguredExternalAction(): void {
    $armed = __DIR__ . '/fixtures/external-actions-ruleset.xml';

    $findings = $this->phpcs('RequiredOnIntake.php', $armed);
    self::assertSame(
      [10 => ['CiviKitchen.Api.NoRequiredOnExternalAction.RequiredOnExternalAction']],
      $findings,
      'the armed ruleset must flag @required on the listed action'
    );

    self::assertSame([], $this->phpcs('RequiredOnImporter.php', $armed),
      'an action outside the externalActions list keeps its legitimate @required');

    // The enclosing class decides, not the first class in the file; the
    // trait's @required (line 19) is outside the class body and not seen.
    self::assertSame(
      [31 => ['CiviKitchen.Api.NoRequiredOnExternalAction.RequiredOnExternalAction']],
      $this->phpcs('RequiredOnSecondClass.php', $armed)
    );
  }

  public function testRequiredGuardMatchesQualifiedEntriesByNamespace(): void {
    $armed = __DIR__ . '/fixtures/external-actions-fqn-ruleset.xml';

    self::assertSame(
      [26 => ['CiviKitchen.Api.NoRequiredOnExternalAction.RequiredOnExternalAction']],
      $this->phpcs('RequiredNamespaced.php', $armed),
      'only Acme\\Api\\RequiredOnIntake is guarded, not Other\\RequiredOnIntake'
    );
  }

}
