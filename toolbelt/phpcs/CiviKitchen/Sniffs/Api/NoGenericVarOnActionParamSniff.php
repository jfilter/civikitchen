<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Api;

use CiviKitchen\Util\Names;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\Namespaces;
use PHPCSUtils\Utils\ObjectDeclarations;
use PHPCSUtils\Utils\Variables;

/**
 * Bans @var types on APIv4 action params that core cannot validate.
 *
 * Core reads those @var tags at RUNTIME, as AbstractAction::getParamInfo()
 * and ReflectionUtils::parseDocBlock() do: protected properties not starting
 * with `_` (and not `version`); the first word of the last @var, lowercased,
 * split on `|`, `null` dropped. ValidateFieldsSubscriber::checkType() knows
 * array, bool, string, int, float and mixed, and throws "Unknown parameter
 * type" on anything else — generics, `int[]`, `?string`, `integer`, pseudo
 * types. The action then crashes on every call that sets the param. Keep the
 * runtime @var plain and put the precise type in a @phpstan-var tag. An
 * `array{…}` shape is accepted: core parses it as array since 6.6.0, the
 * floor of every supported CiviCRM version.
 *
 * A class counts as an action when its parent resolves to a core action
 * (`Civi\Api4\Action\…`, or `Civi\Api4\Generic\…Action`), when it lives in a
 * Civi\Api4 namespace and extends a class whose name ends in "Action", or when
 * it declares `_run()` taking a `Civi\Api4\Generic\Result`. Static properties are
 * skipped: core reads params through `$this->$name`, so they are always NULL.
 */
final class NoGenericVarOnActionParamSniff implements Sniff {

  private const ACCEPTED_TYPES = ['array', 'bool', 'string', 'int', 'float', 'mixed'];

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
    if (!$this->isAction($phpcsFile, $stackPtr)) {
      return;
    }
    $tokens = $phpcsFile->getTokens();

    foreach (ObjectDeclarations::getDeclaredProperties($phpcsFile, $stackPtr) as $name => $variable) {
      $name = ltrim($name, '$');
      $member = Variables::getMemberProperties($phpcsFile, $variable);
      if ($name === 'version' || str_starts_with($name, '_') || $member['scope'] !== 'protected' || $member['is_static']) {
        continue;
      }
      $varTag = $this->lastVarTag($phpcsFile, $variable);
      if ($varTag === NULL) {
        continue;
      }
      $string = $phpcsFile->findNext(T_DOC_COMMENT_STRING, $varTag + 1, NULL, FALSE, NULL, TRUE);
      $text = $string !== FALSE && $tokens[$string]['line'] === $tokens[$varTag]['line'] ? trim($tokens[$string]['content']) : '';
      if (str_starts_with($text, 'array{')) {
        continue;
      }
      $word = preg_split('/\s+/', $text)[0];
      $types = array_diff(explode('|', strtolower($word)), ['null']);
      if (array_diff($types, self::ACCEPTED_TYPES) === []) {
        continue;
      }

      $phpcsFile->addError(
        'APIv4 action param $%s has @var "%s", which core cannot validate at runtime ("Unknown parameter type") — use array, bool, string, int, float or mixed (optionally |null) and put the precise type in @phpstan-var',
        $string !== FALSE && $text !== '' ? $string : $varTag,
        'GenericActionVar',
        [$name, $word]
      );
    }
  }

  private function isAction(File $phpcsFile, int $classPtr): bool {
    $parent = ObjectDeclarations::findExtendedClassName($phpcsFile, $classPtr);
    if ($parent === FALSE) {
      return FALSE;
    }
    $resolved = strtolower(Names::resolveClass($phpcsFile, $classPtr, $parent));
    if (str_starts_with($resolved, 'civi\\api4\\action\\')
      || (str_starts_with($resolved, 'civi\\api4\\generic\\') && str_ends_with($resolved, 'action'))) {
      return TRUE;
    }
    $namespace = Namespaces::determineNamespace($phpcsFile, $classPtr);
    if (($namespace === 'Civi\\Api4' || str_starts_with($namespace, 'Civi\\Api4\\')) && str_ends_with($parent, 'Action')) {
      return TRUE;
    }
    foreach (ObjectDeclarations::getDeclaredMethods($phpcsFile, $classPtr) as $method => $functionPtr) {
      if (strcasecmp((string) $method, '_run') !== 0) {
        continue;
      }
      $type = FunctionDeclarations::getParameters($phpcsFile, $functionPtr)[0]['type_hint'] ?? '';
      return $type !== '' && strcasecmp(Names::resolveClass($phpcsFile, $functionPtr, ltrim($type, '?')), 'Civi\\Api4\\Generic\\Result') === 0;
    }

    return FALSE;
  }

  /**
   * The last @var tag of the docblock directly above the property, if any.
   */
  private function lastVarTag(File $phpcsFile, int $variable): ?int {
    $tokens = $phpcsFile->getTokens();
    $boundary = $phpcsFile->findPrevious([T_DOC_COMMENT_CLOSE_TAG, T_SEMICOLON, T_OPEN_CURLY_BRACKET, T_CLOSE_CURLY_BRACKET], $variable - 1);
    if ($boundary === FALSE || $tokens[$boundary]['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
      return NULL;
    }
    $found = NULL;
    foreach ($tokens[$tokens[$boundary]['comment_opener']]['comment_tags'] as $tag) {
      if ($tokens[$tag]['content'] === '@var') {
        $found = $tag;
      }
    }

    return $found;
  }

}
