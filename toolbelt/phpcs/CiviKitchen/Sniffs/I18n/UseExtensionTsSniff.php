<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\I18n;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * In extension code, translations must go through the extension's own
 * `E::ts()` (the civix `CRM_<Ext>_ExtensionUtil` helper), never the bare
 * global `ts()`: bare ts() resolves in CIVICRM CORE's translation domain, so
 * the extension's .po files are silently never consulted — strings stay
 * untranslated with no error anywhere. The civix scaffolding generates
 * E::ts() throughout for exactly this reason.
 *
 * Flags bare `ts(...)` (including `\ts(...)`) function calls. Method calls
 * (`$x->ts()`), static calls (`E::ts()`, `SomeUtil::ts()`) and declarations
 * are not flagged.
 */
final class UseExtensionTsSniff implements Sniff {

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
    if ($tokens[$stackPtr]['content'] !== 'ts') {
      return;
    }

    $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE);
    if ($next === FALSE || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
      return;
    }

    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, NULL, TRUE);
    if ($prev !== FALSE) {
      $excludedPrev = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
      ];
      if (in_array($tokens[$prev]['code'], $excludedPrev, TRUE)) {
        return;
      }
    }

    $phpcsFile->addError(
      'Bare ts() resolves in core\'s translation domain — use E::ts() (the civix CRM_<Ext>_ExtensionUtil helper) so the extension\'s own translations apply',
      $stackPtr,
      'BareTs'
    );
  }

}
