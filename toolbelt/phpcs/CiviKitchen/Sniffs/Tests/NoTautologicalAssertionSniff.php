<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Tests;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Bans assertions whose argument is a literal matching the assertion.
 *
 * assertTrue(TRUE) is how a "did not throw" smoke test gets written when the
 * author has no postcondition handy. It passes whether or not the code under
 * test did anything, and — worse — it hides the fact from PHPUnit, which
 * would otherwise mark the test risky. State the intent instead:
 *
 *     $this->expectNotToPerformAssertions();
 *
 * or assert the postcondition the call was supposed to produce.
 */
final class NoTautologicalAssertionSniff implements Sniff {

  /**
   * Assertion name (lower case) => the literal that makes it a tautology.
   *
   * @var array<string, string>
   */
  private const TAUTOLOGIES = [
    'asserttrue' => 'true',
    'assertnottrue' => 'false',
    'assertfalse' => 'false',
    'assertnotfalse' => 'true',
  ];

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_STRING];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    $name = strtolower($tokens[$stackPtr]['content']);
    if (!isset(self::TAUTOLOGIES[$name])) {
      return;
    }

    // Only $this->assertX() / self::assertX() / static::assertX().
    $prev = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, NULL, TRUE);
    if ($prev === FALSE || !in_array($tokens[$prev]['code'], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], TRUE)) {
      return;
    }

    $open = $phpcsFile->findNext(T_WHITESPACE, $stackPtr + 1, NULL, TRUE);
    if ($open === FALSE || $tokens[$open]['code'] !== T_OPEN_PARENTHESIS) {
      return;
    }
    $close = $tokens[$open]['parenthesis_closer'] ?? NULL;
    if ($close === NULL) {
      return;
    }

    // Exactly one argument, and that argument a single boolean literal.
    $argument = NULL;
    for ($i = $open + 1; $i < $close; $i++) {
      if ($tokens[$i]['code'] === T_WHITESPACE) {
        continue;
      }
      if ($argument !== NULL) {
        // A second token: an expression, not a bare literal.
        return;
      }
      $argument = $i;
    }
    if ($argument === NULL || strtolower($tokens[$argument]['content']) !== self::TAUTOLOGIES[$name]) {
      return;
    }

    $phpcsFile->addError(
      'Tautological assertion %s(%s) passes regardless of the code under test; use $this->expectNotToPerformAssertions() or assert the postcondition',
      $stackPtr,
      'TautologicalAssertion',
      [$tokens[$stackPtr]['content'], $tokens[$argument]['content']]
    );
  }

}
