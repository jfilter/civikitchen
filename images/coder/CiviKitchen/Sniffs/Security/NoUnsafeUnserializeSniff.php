<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Security;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Requires unserialize() to pass an $options array.
 *
 * Single-argument unserialize() instantiates whatever classes the payload
 * names and runs their __wakeup()/__destruct(). In CiviCRM extensions the
 * payload is routinely a serialized blob from a database column — CiviRules
 * action_params/condition_params, legacy settings, cached rows — so the
 * safety of the call depends on nothing having tampered with that column.
 * Extensions almost always want plain arrays back:
 *
 *     unserialize($raw, ['allowed_classes' => FALSE])
 *
 * The sniff only requires that the decision was made explicitly; it does not
 * insist on FALSE, so a call that legitimately expects objects can pass a
 * class list instead.
 */
final class NoUnsafeUnserializeSniff implements Sniff {

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
    if (strtolower($tokens[$stackPtr]['content']) !== 'unserialize') {
      return;
    }

    // Must be a function call, not a method call or a declaration.
    $prev = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, NULL, TRUE);
    if ($prev !== FALSE && in_array($tokens[$prev]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], TRUE)) {
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

    // A second argument means the options array is being passed. Skip over
    // nested calls and arrays so unserialize(trim($raw, ' ')) still reads as
    // a single argument — a comma one level down is not our comma.
    for ($i = $open + 1; $i < $close; $i++) {
      if ($tokens[$i]['code'] === T_OPEN_PARENTHESIS) {
        $i = $tokens[$i]['parenthesis_closer'] ?? $i;
        continue;
      }
      if ($tokens[$i]['code'] === T_OPEN_SHORT_ARRAY) {
        $i = $tokens[$i]['bracket_closer'] ?? $i;
        continue;
      }
      if ($tokens[$i]['code'] === T_COMMA) {
        return;
      }
    }

    $phpcsFile->addError(
      'unserialize() without an $options array instantiates arbitrary classes from the payload; pass [\'allowed_classes\' => FALSE] (or an explicit class list)',
      $stackPtr,
      'UnsafeUnserialize'
    );
  }

}
