<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Modern;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * A literal TRUE/FALSE passed positionally says nothing at the call site:
 * `$this->save($contact, TRUE)` needs the callee's signature open in another
 * tab to read. Since PHP 8 the fix is one word — `checkPermissions: TRUE`.
 *
 * A warning, not an error: the flag argument is legitimate, only anonymous.
 * `ckmodernize` handles the adjacent case (arguments that merely repeat a
 * default) automatically; this one needs a human to pick the parameter.
 *
 * `ignoreCalls` exempts callees whose bool is idiomatic (in_array's strict
 * flag) or a value rather than a flag: variadics that cannot take a name
 * (array_push), APIv4 field values (addWhere, addHaving, addValue), setting values (set)
 * and PHPUnit's expected values (assertSame). Names match case-insensitively,
 * for functions and methods alike — extend the list via ruleset <property>
 * rather than editing the sniff. `\TRUE` counts as the literal.
 * A setter's sole argument is exempt too: `setUseTrash(FALSE)` names it, and
 * APIv4's magic setters (__call) cannot take a named argument.
 */
final class NameBooleanArgumentsSniff implements Sniff {

  /**
   * Callees whose literal bool argument reads fine without a name.
   *
   * @var array<int, string>
   */
  public $ignoreCalls = [
    'in_array',
    'array_search',
    'array_keys',
    'json_decode',
    'define',
    'array_push',
    'array_unshift',
    'array_fill',
    'array_fill_keys',
    'array_pad',
    'addWhere',
    'addHaving',
    'addValue',
    'set',
    'assertSame',
    'assertEquals',
    'assertNotSame',
    'assertNotEquals',
  ];

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_TRUE, T_FALSE];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    $parentheses = $tokens[$stackPtr]['nested_parenthesis'] ?? [];
    if ($parentheses === []) {
      return;
    }

    // Innermost enclosing parentheses — anything further out is not this
    // token's argument list.
    $opener = (int) array_key_last($parentheses);
    if (!$this->isArgumentOfCall($phpcsFile, $stackPtr, $opener)) {
      return;
    }

    $callee = $phpcsFile->findPrevious(Tokens::$emptyTokens, $opener - 1, NULL, TRUE);
    if ($callee === FALSE || $tokens[$callee]['code'] !== T_STRING) {
      return;
    }
    // A declaration's default value, not a call.
    if ($this->isDeclaration($phpcsFile, $callee)) {
      return;
    }
    if (in_array(strtolower($tokens[$callee]['content']), array_map('strtolower', $this->ignoreCalls), TRUE)) {
      return;
    }
    if ($this->isSoleSetterArgument($phpcsFile, $stackPtr, $opener, $tokens[$callee]['content'])) {
      return;
    }

    $phpcsFile->addWarning(
      'Positional %s says nothing at the call site — name the argument (%s(… , flag: %s))',
      $stackPtr,
      'UnnamedBoolean',
      [$tokens[$stackPtr]['content'], $tokens[$callee]['content'], $tokens[$stackPtr]['content']]
    );
  }

  /**
   * The token stands alone as one argument (not part of a larger expression)
   * and is not already named.
   */
  private function isArgumentOfCall(File $phpcsFile, int $stackPtr, int $opener): bool {
    $tokens = $phpcsFile->getTokens();
    $prev = $this->previousBeforeLiteral($phpcsFile, $stackPtr);
    $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE);
    if ($prev === FALSE || $next === FALSE) {
      return FALSE;
    }
    // `flag: TRUE` — already named, which is the point of this sniff.
    if ($tokens[$prev]['code'] === T_COLON) {
      return FALSE;
    }
    if ($prev !== $opener && $tokens[$prev]['code'] !== T_COMMA) {
      return FALSE;
    }

    return $tokens[$next]['code'] === T_COMMA || $tokens[$next]['code'] === T_CLOSE_PARENTHESIS;
  }

  private function isSoleSetterArgument(File $phpcsFile, int $stackPtr, int $opener, string $callee): bool {
    if (preg_match('/^set[A-Z]/', $callee) !== 1) {
      return FALSE;
    }
    $tokens = $phpcsFile->getTokens();
    $prev = $this->previousBeforeLiteral($phpcsFile, $stackPtr);
    $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE);

    return $prev === $opener && $next !== FALSE && $tokens[$next]['code'] === T_CLOSE_PARENTHESIS;
  }

  /**
   * The token before the literal, stepping over the `\` of `\TRUE`.
   *
   * @return int|false
   */
  private function previousBeforeLiteral(File $phpcsFile, int $stackPtr) {
    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, NULL, TRUE);
    if ($prev !== FALSE && $phpcsFile->getTokens()[$prev]['code'] === T_NS_SEPARATOR) {
      return $phpcsFile->findPrevious(Tokens::$emptyTokens, $prev - 1, NULL, TRUE);
    }

    return $prev;
  }

  private function isDeclaration(File $phpcsFile, int $callee): bool {
    $tokens = $phpcsFile->getTokens();
    $before = $phpcsFile->findPrevious(Tokens::$emptyTokens, $callee - 1, NULL, TRUE);

    return $before !== FALSE && in_array($tokens[$before]['code'], [T_FUNCTION, T_FN], TRUE);
  }

}
