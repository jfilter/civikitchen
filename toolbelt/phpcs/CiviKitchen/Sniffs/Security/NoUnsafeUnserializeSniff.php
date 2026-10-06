<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Security;

use CiviKitchen\Util\Calls;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\Arrays;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;

/**
 * Requires unserialize() to decide `allowed_classes` explicitly.
 *
 * Without it unserialize() instantiates whatever classes the payload names
 * and runs their __wakeup()/__destruct() — `allowed_classes` defaults to
 * TRUE, also for an empty or partial options array. In CiviCRM extensions the
 * payload is routinely a serialized blob from a database column — CiviRules
 * action_params/condition_params, legacy settings, cached rows — so the
 * safety of the call depends on nothing having tampered with that column.
 * Extensions almost always want plain arrays back:
 *
 *     unserialize($raw, ['allowed_classes' => FALSE])
 *
 * The sniff only requires that the decision was made explicitly; it does not
 * insist on FALSE, so a call that legitimately expects objects can pass a
 * class list instead. An options argument that is not an array literal (a
 * variable, a merge) cannot be read statically and is accepted, and so is an
 * array literal with a computed key or a spread. A `'unserialize'` string
 * callable (array_map() and the other Calls::CALLBACK_POSITIONS functions)
 * passes no options at all and is flagged.
 */
final class NoUnsafeUnserializeSniff implements Sniff {

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
    if (Calls::globalFunctionName($phpcsFile, $stackPtr) === 'unserialize') {
      $options = PassedParameters::getParameter($phpcsFile, $stackPtr, 2, 'options');
      if ($options !== FALSE && !$this->lacksAllowedClasses($phpcsFile, $options)) {
        return;
      }
    }
    elseif (!Calls::isStringCallable($phpcsFile, $stackPtr, 'unserialize')) {
      return;
    }

    $phpcsFile->addError(
      'unserialize() without an allowed_classes option instantiates arbitrary classes from the payload; pass [\'allowed_classes\' => FALSE] (or an explicit class list)',
      $stackPtr,
      'UnsafeUnserialize'
    );
  }

  /**
   * The options argument is an array literal whose keys are all literals and
   * none of them `allowed_classes`.
   *
   * @param array<string, int|string> $options
   */
  private function lacksAllowedClasses(File $phpcsFile, array $options): bool {
    $tokens = $phpcsFile->getTokens();
    $significant = Calls::significantTokens($phpcsFile, $options);
    $opener = $significant[0];
    if (!in_array($tokens[$opener]['code'], [T_OPEN_SHORT_ARRAY, T_ARRAY], TRUE)) {
      return FALSE;
    }
    $bounds = Arrays::getOpenClose($phpcsFile, $opener);
    if ($bounds === FALSE || $bounds['closer'] !== end($significant)) {
      return FALSE;
    }

    foreach (PassedParameters::getParameters($phpcsFile, $opener) as $item) {
      $arrow = Arrays::getDoubleArrowPtr($phpcsFile, (int) $item['start'], (int) $item['end']);
      if ($arrow === FALSE) {
        if ($tokens[Calls::significantTokens($phpcsFile, $item)[0]]['code'] === T_ELLIPSIS) {
          return FALSE;
        }
        continue;
      }
      $key = Calls::soleToken($phpcsFile, ['start' => $item['start'], 'end' => $arrow - 1]);
      if ($key === NULL || !in_array($tokens[$key]['code'], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], TRUE)) {
        return FALSE;
      }
      if (TextStrings::stripQuotes($tokens[$key]['content']) === 'allowed_classes') {
        return FALSE;
      }
    }

    return TRUE;
  }

}
