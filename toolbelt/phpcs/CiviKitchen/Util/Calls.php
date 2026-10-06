<?php

declare(strict_types=1);

namespace CiviKitchen\Util;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;

/**
 * Call-site helpers shared by the CiviKitchen sniffs. Autoloaded through the
 * standard's installed path or ruleset directory (namespace CiviKitchen).
 */
final class Calls {

  /**
   * Higher-order function (lower case) => 1-based position of its callback,
   * which every one of them also accepts as the named argument `callback:`.
   */
  public const CALLBACK_POSITIONS = [
    'array_map' => 1,
    'call_user_func' => 1,
    'call_user_func_array' => 1,
    'array_filter' => 2,
    'array_walk' => 2,
    'array_walk_recursive' => 2,
    'usort' => 2,
    'uasort' => 2,
    'uksort' => 2,
    'array_reduce' => 2,
  ];

  /**
   * Tokens before a name that make it a member, a declaration or a class.
   */
  private const NOT_A_FUNCTION_CALL = [
    T_OBJECT_OPERATOR,
    T_NULLSAFE_OBJECT_OPERATOR,
    T_DOUBLE_COLON,
    T_FUNCTION,
    T_NEW,
    T_CONST,
  ];

  /**
   * The T_STRING at $ptr calls the global function of that name: followed by
   * `(`, not a member, declaration or `new`, at most prefixed by a lone `\`.
   */
  public static function isGlobalFunctionCall(File $phpcsFile, int $ptr): bool {
    $tokens = $phpcsFile->getTokens();
    if ($tokens[$ptr]['code'] !== T_STRING) {
      return FALSE;
    }
    $next = $phpcsFile->findNext(Tokens::$emptyTokens, $ptr + 1, NULL, TRUE);
    if ($next === FALSE || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
      return FALSE;
    }
    $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $ptr - 1, NULL, TRUE);
    if ($prev !== FALSE && $tokens[$prev]['code'] === T_NS_SEPARATOR) {
      // `Ns\name` and `namespace\name` are other functions; only `\name` is global.
      if (in_array($tokens[$prev - 1]['code'], [T_STRING, T_NAMESPACE], TRUE)) {
        return FALSE;
      }
      $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $prev - 1, NULL, TRUE);
    }

    return $prev === FALSE || !in_array($tokens[$prev]['code'], self::NOT_A_FUNCTION_CALL, TRUE);
  }

  /**
   * The global function name called at $ptr, lower case, or NULL.
   */
  public static function globalFunctionName(File $phpcsFile, int $ptr): ?string {
    if (!self::isGlobalFunctionCall($phpcsFile, $ptr)) {
      return NULL;
    }

    return strtolower($phpcsFile->getTokens()[$ptr]['content']);
  }

  /**
   * The string literal at $ptr is the whole callback argument of a
   * CALLBACK_POSITIONS function and names $function (case-insensitive).
   */
  public static function isStringCallable(File $phpcsFile, int $ptr, string $function): bool {
    $tokens = $phpcsFile->getTokens();
    if ($tokens[$ptr]['code'] !== T_CONSTANT_ENCAPSED_STRING
      || strcasecmp(TextStrings::stripQuotes($tokens[$ptr]['content']), $function) !== 0) {
      return FALSE;
    }
    $parentheses = $tokens[$ptr]['nested_parenthesis'] ?? [];
    if ($parentheses === []) {
      return FALSE;
    }
    $callee = $phpcsFile->findPrevious(Tokens::$emptyTokens, (int) array_key_last($parentheses) - 1, NULL, TRUE);
    $name = $callee === FALSE ? NULL : self::globalFunctionName($phpcsFile, $callee);
    if ($name === NULL || !isset(self::CALLBACK_POSITIONS[$name])) {
      return FALSE;
    }
    $argument = PassedParameters::getParameter($phpcsFile, $callee, self::CALLBACK_POSITIONS[$name], 'callback');

    return $argument !== FALSE && self::soleToken($phpcsFile, $argument) === $ptr;
  }

  /**
   * The one non-empty token of a PassedParameters argument, or NULL when the
   * argument is an expression of several tokens.
   *
   * @param array<string, int|string> $argument
   */
  public static function soleToken(File $phpcsFile, array $argument): ?int {
    $tokens = self::significantTokens($phpcsFile, $argument);

    return count($tokens) === 1 ? $tokens[0] : NULL;
  }

  /**
   * The non-empty tokens of a PassedParameters argument, in order.
   *
   * @param array<string, int|string> $argument
   *
   * @return list<int>
   */
  public static function significantTokens(File $phpcsFile, array $argument): array {
    $found = [];
    for ($i = (int) $argument['start']; $i <= (int) $argument['end']; $i++) {
      if (!isset(Tokens::$emptyTokens[$phpcsFile->getTokens()[$i]['code']])) {
        $found[] = $i;
      }
    }

    return $found;
  }

  /**
   * The argument is a bare TRUE/FALSE literal (optionally `\`-qualified and
   * behind `!` operators): its boolean value, else NULL.
   *
   * @param array<string, int|string> $argument
   */
  public static function booleanValue(File $phpcsFile, array $argument): ?bool {
    $tokens = $phpcsFile->getTokens();
    $negations = 0;
    $significant = self::significantTokens($phpcsFile, $argument);
    while ($significant !== [] && $tokens[$significant[0]]['code'] === T_BOOLEAN_NOT) {
      array_shift($significant);
      $negations++;
    }
    if ($significant !== [] && $tokens[$significant[0]]['code'] === T_NS_SEPARATOR) {
      array_shift($significant);
    }
    if (count($significant) !== 1 || !in_array($tokens[$significant[0]]['code'], [T_TRUE, T_FALSE], TRUE)) {
      return NULL;
    }

    return ($tokens[$significant[0]]['code'] === T_TRUE) !== ($negations % 2 === 1);
  }

}
