<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Api;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Bans generic types in property @var tags of APIv4 action classes.
 *
 * Core's ValidateFieldsSubscriber parses those @var tags at RUNTIME to
 * type-check the API parameters and throws "Unknown parameter type" on any
 * generic (`array<int, string>` etc.) — the action then crashes on every
 * call that sets the param. Keep the runtime @var plain (`array`) and put
 * the precise type in a @phpstan-var tag.
 *
 * A class counts as an action when it lives in a Civi\Api4 namespace AND
 * extends a class whose name ends in "Action" (AbstractAction,
 * AbstractGetAction, BasicBatchAction, project intermediates ...). The
 * namespace guard keeps CiviRules-style *Action bases out.
 */
final class NoGenericVarOnActionParamSniff implements Sniff {

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_CLASS];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $extended = $phpcsFile->findExtendedClassName($stackPtr);
    if ($extended === FALSE || !str_ends_with(ltrim($extended, '\\'), 'Action')) {
      return;
    }
    $nsPtr = $phpcsFile->findPrevious(T_NAMESPACE, $stackPtr);
    if ($nsPtr === FALSE) {
      return;
    }
    $nsEnd = $phpcsFile->findNext([T_SEMICOLON, T_OPEN_CURLY_BRACKET], $nsPtr);
    $namespace = trim($phpcsFile->getTokensAsString($nsPtr + 1, $nsEnd - $nsPtr - 1));
    if (!str_starts_with($namespace, 'Civi\\Api4')) {
      return;
    }

    $tokens = $phpcsFile->getTokens();
    $classEnd = $tokens[$stackPtr]['scope_closer'] ?? NULL;
    if ($classEnd === NULL) {
      return;
    }

    for ($i = $tokens[$stackPtr]['scope_opener'] + 1; $i < $classEnd; $i++) {
      if ($tokens[$i]['code'] !== T_DOC_COMMENT_TAG || $tokens[$i]['content'] !== '@var') {
        continue;
      }
      // Only property docblocks: the docblock must be at class-body level,
      // i.e. NOT inside a method. conditions of the tag token tell us.
      $conditions = array_keys($tokens[$i]['conditions']);
      if (end($conditions) !== $stackPtr) {
        continue;
      }
      $typeString = $phpcsFile->findNext(T_DOC_COMMENT_STRING, $i + 1, $i + 4);
      if ($typeString !== FALSE && str_contains($tokens[$typeString]['content'], '<')) {
        $phpcsFile->addError(
          'Generic in runtime-parsed @var of an APIv4 action param ("%s") — core throws "Unknown parameter type"; use plain @var plus @phpstan-var',
          $typeString,
          'GenericActionVar',
          [trim($tokens[$typeString]['content'])]
        );
      }
    }
  }

}
