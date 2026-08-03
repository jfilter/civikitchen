<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Every PHP file declares strict_types.
 *
 * Without it PHP coerces at every typed boundary: a `"12 contacts"` reaching an
 * `int $id` becomes 12, and the type declarations phpstan checks against are
 * enforced by nothing at runtime. It is per FILE — one missing declare is one
 * file's worth of silent coercion, which is why this is a sniff and not a
 * review habit.
 *
 * Auto-fixable: phpcbf inserts the declare after the opening tag.
 */
final class RequireStrictTypesSniff implements Sniff {

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_OPEN_TAG];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    // Only the file's first opening tag; inline HTML may reopen PHP later.
    if ($phpcsFile->findPrevious(T_OPEN_TAG, $stackPtr - 1) !== FALSE) {
      return;
    }
    if ($this->hasStrictTypes($phpcsFile)) {
      return;
    }
    // A file that holds no code at all has nothing to coerce.
    if ($phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE) === FALSE) {
      return;
    }

    $fix = $phpcsFile->addFixableError(
      'Missing declare(strict_types=1) — without it PHP silently coerces at every typed boundary',
      $stackPtr,
      'MissingStrictTypes'
    );
    if ($fix) {
      $phpcsFile->fixer->addContent($stackPtr, "\ndeclare(strict_types=1);\n");
    }
  }

  private function hasStrictTypes(File $phpcsFile): bool {
    $tokens = $phpcsFile->getTokens();
    $declare = $phpcsFile->findNext(T_DECLARE, 0);
    while ($declare !== FALSE) {
      $end = $tokens[$declare]['parenthesis_closer'] ?? NULL;
      if ($end !== NULL) {
        $directive = $phpcsFile->findNext(T_STRING, $declare, $end, FALSE, 'strict_types');
        // The value is only ever 0 or 1; 0 declares nothing.
        if ($directive !== FALSE && $phpcsFile->findNext(T_LNUMBER, $directive, $end, FALSE, '1') !== FALSE) {
          return TRUE;
        }
      }
      $declare = $phpcsFile->findNext(T_DECLARE, $declare + 1);
    }

    return FALSE;
  }

}
