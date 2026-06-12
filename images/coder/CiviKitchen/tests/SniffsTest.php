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

  private const SNIFFS = 'CiviKitchen.Legacy.NoLegacyCall,CiviKitchen.I18n.UseExtensionTs,CiviKitchen.Api.NoRequiredOnExternalAction';

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
  private function phpcs(string $fixture, ?string $standard = NULL): array {
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
      escapeshellarg(self::SNIFFS),
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

  public function testNoLegacyCallFlagsEveryDefaultBanOnTheExactLine(): void {
    $findings = $this->phpcs('LegacyCalls.php');

    $expected = [
      7 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyFunction'],
      8 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyFunction'],
      9 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyStaticCall'],
      10 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyStaticCall'],
      11 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyStaticCall'],
      12 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyStaticCall'],
      13 => ['CiviKitchen.Legacy.NoLegacyCall.LegacyStaticCall'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testUseExtensionTsFlagsBareAndFullyQualifiedTs(): void {
    $findings = $this->phpcs('BareTs.php');

    $expected = [
      7 => ['CiviKitchen.I18n.UseExtensionTs.BareTs'],
      8 => ['CiviKitchen.I18n.UseExtensionTs.BareTs'],
    ];
    self::assertSame($expected, $findings);
  }

  public function testModernCounterpartsProduceZeroFindings(): void {
    self::assertSame([], $this->phpcs('CleanModern.php'));
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
