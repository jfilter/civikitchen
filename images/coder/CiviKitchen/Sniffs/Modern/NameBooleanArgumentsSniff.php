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
 * flag) — extend it via ruleset <property> rather than editing the sniff.
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
    if (in_array($tokens[$callee]['content'], $this->ignoreCalls, TRUE)) {
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
    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, NULL, TRUE);
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

  private function isDeclaration(File $phpcsFile, int $callee): bool {
    $tokens = $phpcsFile->getTokens();
    $before = $phpcsFile->findPrevious(Tokens::$emptyTokens, $callee - 1, NULL, TRUE);

    return $before !== FALSE && in_array($tokens[$before]['code'], [T_FUNCTION, T_FN], TRUE);
  }

}
