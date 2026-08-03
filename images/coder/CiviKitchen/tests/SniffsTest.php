<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CiviKitchen sniffs, run against the REAL phpcs binary
 * (the one the image ships): fixtures with known violations must produce
 * exactly the expected sniff codes on the expected lines, and the
 * modern-counterpart fixture must produce zero findings — every line in it
 * is a near-miss a sloppy token matcher would flag.
 *
 * Runs anywhere phpcs + the CiviKitchen standard are available:
 *   - inside a civikitchen image:  phpunit /opt/civikitchen-coder/CiviKitchen/tests
 *   - from a repo checkout:        phpunit images/coder/CiviKitchen/tests
 *     (the standard is resolved via --runtime-set installed_paths below,
 *      so the repo copy needs no prior phpcs --config-set)
 */
final class SniffsTest extends TestCase {

  private const SNIFFS = 'CiviKitchen.Legacy.NoLegacyPageForm,'
    . 'CiviKitchen.I18n.UseExtensionTs,'
    . 'CiviKitchen.Api.NoRequiredOnExternalAction,'
    . 'CiviKitchen.Api.NoGenericVarOnActionParam,'
    . 'CiviKitchen.Security.NoUnsafeUnserialize,'
    . 'CiviKitchen.Security.PermissionBypass,'
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

  public function testUseExtensionTsFlagsBareAndFullyQualifiedTs(): void {
    $findings = $this->phpcs('BareTs.php');

    $expected = [
      7 => ['CiviKitchen.I18n.UseExtensionTs.BareTs'],
      8 => ['CiviKitchen.I18n.UseExtensionTs.BareTs'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testUseMixinsForStandardHooksFlagsLegacyMixinHooks(): void {
    $findings = $this->phpcs('LegacyMixinHooks.php');

    $expected = [
      6 => ['CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'],
      9 => ['CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'],
      12 => ['CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'],
      15 => ['CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'],
      18 => ['CiviKitchen.Extension.UseMixinsForStandardHooks.LegacyHook'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testNoLegacyPageFormWarnsOnEveryDefaultLegacyUiBase(): void {
    $findings = $this->phpcs('LegacyPageForm.php');

    $expected = [
      6 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
      8 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
      10 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
      12 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
      14 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
      16 => ['CiviKitchen.Legacy.NoLegacyPageForm.LegacyUiBase'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testNoGenericVarOnActionParamFlagsRuntimeParsedGenerics(): void {
    $findings = $this->phpcs('GenericActionVar.php');

    // Flags: generic @var on params of Civi\Api4 classes extending *Action
    // (lines 12 + 33). Not flagged: plain @var with @phpstan-var, inline
    // @var inside a method body, non-Action base class.
    $expected = [
      12 => ['CiviKitchen.Api.NoGenericVarOnActionParam.GenericActionVar'],
      33 => ['CiviKitchen.Api.NoGenericVarOnActionParam.GenericActionVar'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testNoUnsafeUnserializeFlagsOnlySingleArgumentCalls(): void {
    $findings = $this->phpcs('UnsafeUnserialize.php');

    // Flagged: the three one-argument calls (a comma inside a nested call
    // argument must not read as a second argument, line 13). Not flagged:
    // calls passing an options array, ->unserialize()/::unserialize() method
    // calls, and the method declaration itself.
    $expected = [
      12 => ['CiviKitchen.Security.NoUnsafeUnserialize.UnsafeUnserialize'],
      13 => ['CiviKitchen.Security.NoUnsafeUnserialize.UnsafeUnserialize'],
      14 => ['CiviKitchen.Security.NoUnsafeUnserialize.UnsafeUnserialize'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testNoTautologicalAssertionFlagsOnlyBareMatchingLiterals(): void {
    $findings = $this->phpcs('TautologicalAssertion.php');

    // Flagged: the four assertions on a bare literal that matches them. Not
    // flagged: assertions on expressions, assertTrue(FALSE) (a deliberate
    // failure, not a tautology), assertSame, and a bare function call.
    $expected = [
      12 => ['CiviKitchen.Tests.NoTautologicalAssertion.TautologicalAssertion'],
      13 => ['CiviKitchen.Tests.NoTautologicalAssertion.TautologicalAssertion'],
      14 => ['CiviKitchen.Tests.NoTautologicalAssertion.TautologicalAssertion'],
      15 => ['CiviKitchen.Tests.NoTautologicalAssertion.TautologicalAssertion'],
    ];
    self::assertSame($expected, $findings);
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
    // fixtures above carry `strict_types=1` — unspaced, which the sniff only
    // tolerates because this standard configures it to.
    self::assertSame(
      [1 => ['SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing']],
      $findings
    );
  }

  public function testNameBooleanArgumentsFlagsOnlyBarePositionalLiterals(): void {
    $findings = $this->phpcs('BooleanArgs.php', NULL, self::PHP8_SNIFFS);

    // Flagged: the two positional literals. Not flagged: in_array's strict
    // flag (ignoreCalls), an already-named argument, a variable, a comparison
    // that merely contains TRUE, an assignment, an array element, and the
    // parameter default in the declaration.
    $expected = [
      9 => ['CiviKitchen.Modern.NameBooleanArguments.UnnamedBoolean'],
      10 => ['CiviKitchen.Modern.NameBooleanArguments.UnnamedBoolean'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testMaxFileLengthFlagsOnlyFilesOverTheConfiguredCap(): void {
    // Under the generous default cap (1000) a small file is never flagged.
    self::assertSame([], $this->phpcs('LongFile.php'),
      'a short file is well within the default cap');

    // Armed with a low cap (maxLines=10), the same file trips on line 1 — the
    // over-length is a whole-file fact, reported at the open tag.
    $armed = __DIR__ . '/fixtures/max-file-length-ruleset.xml';
    self::assertSame(
      [1 => ['CiviKitchen.Files.MaxFileLength.TooLong']],
      $this->phpcs('LongFile.php', $armed),
      'a file over the configured cap is flagged on line 1'
    );
  }

  public function testPermissionBypassWarnsOnlyOnTheTwoLiteralForms(): void {
    // The standard excludes tests/, and the fixture lives there — so the
    // sniff has to be armed by path to see it at all. That the plain standard
    // stays silent on the very same file IS the exclusion test below.
    $armed = __DIR__ . '/fixtures/permission-bypass-ruleset.xml';

    $expected = [
      15 => ['CiviKitchen.Security.PermissionBypass.PermissionBypass'],
      16 => ['CiviKitchen.Security.PermissionBypass.PermissionBypass'],
      17 => ['CiviKitchen.Security.PermissionBypass.PermissionBypass'],
      20 => ['CiviKitchen.Security.PermissionBypass.PermissionBypass'],
    ];
    self::assertSame($expected, $this->phpcs('PermissionBypass.php', $armed));
  }

  public function testPermissionBypassIsSilentUnderTests(): void {
    self::assertSame([], $this->phpcs('PermissionBypass.php'),
      'the standard excludes tests/, where running as nobody is the norm');
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
  }

}
