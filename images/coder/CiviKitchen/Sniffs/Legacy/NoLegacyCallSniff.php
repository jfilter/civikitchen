<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Legacy;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Bans legacy CiviCRM call sites that have modern, mandatory replacements.
 *
 * - APIv3 entry points (`civicrm_api3()`, `civicrm_api()`): the extensions are
 *   APIv4-first; v3 must never creep back in.
 * - `CRM_Utils_Array::value()`: superseded by the null-coalescing operator.
 * - `PEAR::raiseError()`: in production code mailer/PEAR errors must be built
 *   as `new \PEAR_Error(...)` — `PEAR::raiseError()` THROWS under the UnitTests
 *   UF, so it is only ever legitimate inside test doubles (exclude tests/ in
 *   the consuming ruleset).
 *
 * Both lists are configurable via ruleset <property> so other extensions can
 * extend them without editing the sniff.
 */
final class NoLegacyCallSniff implements Sniff {

  /**
   * Banned plain function calls: name => guidance shown in the message.
   *
   * @var array<string, string>
   */
  public $bannedFunctions = [
    'civicrm_api3' => 'use civicrm_api4() / the APIv4 OO builders',
    'civicrm_api' => 'use civicrm_api4() / the APIv4 OO builders',
  ];

  /**
   * Banned static calls "Class::method" => guidance.
   *
   * @var array<string, string>
   */
  public $bannedStaticCalls = [
    'CRM_Utils_Array::value' => 'use the null-coalescing operator: $array[$key] ?? $default',
    'PEAR::raiseError' => 'construct new \\PEAR_Error(...) — raiseError throws under UnitTests',
    'CRM_Core_Error::fatal' => 'throw an exception — fatal() is gone from core',
    'CRM_Core_Error::debug_log_message' => 'use \\Civi::log()',
    'CRM_Core_Error::debug_var' => 'use \\Civi::log() with a context array',
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
    $name = $tokens[$stackPtr]['content'];

    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, NULL, TRUE);
    $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, NULL, TRUE);
    $isCall = $next !== FALSE && $tokens[$next]['code'] === T_OPEN_PARENTHESIS;
    if (!$isCall || $prev === FALSE) {
      return;
    }

    // Static call: <Class> :: <name> ( … )
    if ($tokens[$prev]['code'] === T_DOUBLE_COLON) {
      $classPtr = $phpcsFile->findPrevious(Tokens::$emptyTokens, $prev - 1, NULL, TRUE);
      if ($classPtr === FALSE || $tokens[$classPtr]['code'] !== T_STRING) {
        return;
      }
      $key = $tokens[$classPtr]['content'] . '::' . $name;
      if (isset($this->bannedStaticCalls[$key])) {
        $phpcsFile->addError(
          'Legacy static call %s is banned — %s',
          $stackPtr,
          'LegacyStaticCall',
          [$key, $this->bannedStaticCalls[$key]]
        );
      }
      return;
    }

    // Plain function call: not a method (->name), not a static (::name),
    // not a declaration (function name), not "new name(".
    $excludedPrev = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
    if (in_array($tokens[$prev]['code'], $excludedPrev, TRUE)) {
      return;
    }
    if (isset($this->bannedFunctions[$name])) {
      $phpcsFile->addError(
        'Legacy call %s() is banned — %s',
        $stackPtr,
        'LegacyFunction',
        [$name, $this->bannedFunctions[$name]]
      );
    }
  }

}
