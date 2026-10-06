<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\I18n;

use CiviKitchen\Util\Calls;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * In extension code, translations must go through the extension's own
 * `E::ts()` (the civix `CRM_<Ext>_ExtensionUtil` helper), never the bare
 * global `ts()`: bare ts() resolves in CIVICRM CORE's translation domain, so
 * the extension's .po files are silently never consulted — strings stay
 * untranslated with no error anywhere. The civix scaffolding generates
 * E::ts() throughout for exactly this reason.
 *
 * Flags calls of the global function in any case (`ts()`, `\TS()`) and a
 * `'ts'` string that is the whole callback argument of array_map(),
 * call_user_func() and the other Calls::CALLBACK_POSITIONS functions. Method
 * calls (`$x->ts()`), static calls (`E::ts()`), declarations and namespaced
 * functions (`\Some\Ns\ts()`) are not flagged.
 */
final class UseExtensionTsSniff implements Sniff {

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_STRING, T_CONSTANT_ENCAPSED_STRING];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $isCall = Calls::globalFunctionName($phpcsFile, $stackPtr) === 'ts';
    if (!$isCall && !Calls::isStringCallable($phpcsFile, $stackPtr, 'ts')) {
      return;
    }

    $phpcsFile->addError(
      'Bare ts() resolves in core\'s translation domain — use E::ts() (the civix CRM_<Ext>_ExtensionUtil helper) so the extension\'s own translations apply',
      $stackPtr,
      'BareTs'
    );
  }

}
