<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Tests;

use CiviKitchen\Util\Calls;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\PassedParameters;

/**
 * Bans assertions whose outcome is fixed by literals.
 *
 * assertTrue(TRUE) is how a "did not throw" smoke test gets written when the
 * author has no postcondition handy. It passes whether or not the code under
 * test did anything, and — worse — it hides the fact from PHPUnit, which
 * would otherwise mark the test risky. State the intent instead:
 *
 *     $this->expectNotToPerformAssertions();
 *
 * or assert the postcondition the call was supposed to produce. Covered:
 * assertTrue/False/NotTrue/NotFalse on a matching boolean literal (also
 * `\TRUE` and `!` chains), assertNull(NULL), assertEmpty([]), and
 * assertSame/assertEquals on two identical scalar literals. The message
 * argument is ignored; named arguments are read by name.
 */
final class NoTautologicalAssertionSniff implements Sniff {

  /**
   * Boolean assertion (lower case) => the value that makes it a tautology.
   */
  private const BOOLEAN_ASSERTIONS = [
    'asserttrue' => TRUE,
    'assertnottrue' => FALSE,
    'assertfalse' => FALSE,
    'assertnotfalse' => TRUE,
  ];

  /**
   * Tokens that form a scalar literal argument of assertSame/assertEquals.
   */
  private const SCALAR_LITERALS = [
    T_TRUE,
    T_FALSE,
    T_NULL,
    T_LNUMBER,
    T_DNUMBER,
    T_CONSTANT_ENCAPSED_STRING,
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

    // Only $this->assertX() / $this?->assertX() / self::, static::, Assert::.
    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, NULL, TRUE);
    if ($prev === FALSE || !in_array($tokens[$prev]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], TRUE)) {
      return;
    }
    $open = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE);
    if ($open === FALSE || $tokens[$open]['code'] !== T_OPEN_PARENTHESIS || !$this->isTautology($phpcsFile, $stackPtr, $name)) {
      return;
    }

    $phpcsFile->addError(
      'Tautological assertion %s() passes regardless of the code under test; use $this->expectNotToPerformAssertions() or assert the postcondition',
      $stackPtr,
      'TautologicalAssertion',
      [$tokens[$stackPtr]['content']]
    );
  }

  private function isTautology(File $phpcsFile, int $stackPtr, string $name): bool {
    if (isset(self::BOOLEAN_ASSERTIONS[$name])) {
      $condition = PassedParameters::getParameter($phpcsFile, $stackPtr, 1, 'condition');
      return $condition !== FALSE && Calls::booleanValue($phpcsFile, $condition) === self::BOOLEAN_ASSERTIONS[$name];
    }
    if ($name === 'assertnull' || $name === 'assertempty') {
      $actual = PassedParameters::getParameter($phpcsFile, $stackPtr, 1, 'actual');
      $literal = $actual === FALSE ? '' : strtolower(ltrim($this->literal($phpcsFile, $actual), '\\'));
      return $name === 'assertnull' ? $literal === 'null' : in_array($literal, ['[]', 'array()'], TRUE);
    }
    if ($name === 'assertsame' || $name === 'assertequals') {
      $expected = PassedParameters::getParameter($phpcsFile, $stackPtr, 1, 'expected');
      $actual = PassedParameters::getParameter($phpcsFile, $stackPtr, 2, 'actual');
      if ($expected === FALSE || $actual === FALSE) {
        return FALSE;
      }
      $left = $this->scalarLiteral($phpcsFile, $expected);
      return $left !== NULL && $left === $this->scalarLiteral($phpcsFile, $actual);
    }

    return FALSE;
  }

  /**
   * The argument's tokens without whitespace and comments.
   *
   * @param array<string, int|string> $argument
   */
  private function literal(File $phpcsFile, array $argument): string {
    $tokens = $phpcsFile->getTokens();
    $text = '';
    foreach (Calls::significantTokens($phpcsFile, $argument) as $ptr) {
      $text .= $tokens[$ptr]['content'];
    }

    return $text;
  }

  /**
   * A normalized spelling of a scalar literal argument, or NULL.
   *
   * @param array<string, int|string> $argument
   */
  private function scalarLiteral(File $phpcsFile, array $argument): ?string {
    $tokens = $phpcsFile->getTokens();
    $significant = Calls::significantTokens($phpcsFile, $argument);
    $sign = '';
    if ($significant !== [] && in_array($tokens[$significant[0]]['code'], [T_NS_SEPARATOR, T_MINUS], TRUE)) {
      $sign = $tokens[array_shift($significant)]['code'] === T_MINUS ? '-' : '';
    }
    if (count($significant) !== 1 || !in_array($tokens[$significant[0]]['code'], self::SCALAR_LITERALS, TRUE)) {
      return NULL;
    }
    $content = $tokens[$significant[0]]['content'];

    return $sign . (in_array($tokens[$significant[0]]['code'], [T_TRUE, T_FALSE, T_NULL], TRUE) ? strtolower($content) : $content);
  }

}
