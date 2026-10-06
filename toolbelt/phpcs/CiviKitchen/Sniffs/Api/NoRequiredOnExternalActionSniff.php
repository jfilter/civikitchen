<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Api;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\Conditions;
use PHPCSUtils\Utils\Namespaces;

/**
 * Forbids the `@required` APIv4 annotation on externally reachable actions.
 *
 * The trap (documented, cost a real bug): on an action that must answer
 * business rejections as a verdict (never an exception — an APIv4 throw is a
 * proxy HTTP 500 the upstream queue retries forever), `@required` rejects an
 * empty/`false`/missing param BEFORE the action runs. Requiredness for these
 * actions must live in the validation method as a verdict response instead.
 *
 * Admin-only actions (importers etc.) use `@required` legitimately, so this is
 * NOT a blanket ban: the consuming ruleset lists exactly the external action
 * classes via <property name="externalActions">. With an empty list the sniff
 * is inert — it never guesses which actions are external.
 *
 * The sniff checks the class whose body holds the docblock, and only sees
 * declarations in that class body: a `@required` property inherited from a
 * trait or a parent class is not reported.
 */
final class NoRequiredOnExternalActionSniff implements Sniff {

  /**
   * The externally reachable actions to guard. An entry with a `\` is matched
   * against the namespace-qualified class name (`Civi\Api4\Action\Intake`),
   * one without against the short name only (`Intake`, in any namespace).
   * Case-insensitive, like PHP class names. Set per project; empty = inert.
   *
   * @var array<int, string>
   */
  public $externalActions = [];

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_DOC_COMMENT_TAG];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    if ($this->externalActions === []) {
      return;
    }
    $tokens = $phpcsFile->getTokens();
    if (strtolower($tokens[$stackPtr]['content']) !== '@required') {
      return;
    }

    $classPtr = Conditions::getLastCondition($phpcsFile, $stackPtr, [T_CLASS]);
    $className = $classPtr === FALSE ? NULL : $phpcsFile->getDeclarationName($classPtr);
    if ($className === NULL || !$this->isExternal($phpcsFile, $classPtr, $className)) {
      return;
    }

    $phpcsFile->addError(
      '@required is forbidden on the externally reachable action %s — enforce requiredness as a rejected verdict in the validation method, never as an APIv4 exception (a proxy HTTP 500 is retried forever)',
      $stackPtr,
      'RequiredOnExternalAction',
      [$className]
    );
  }

  private function isExternal(File $phpcsFile, int $classPtr, string $className): bool {
    $qualified = ltrim(Namespaces::determineNamespace($phpcsFile, $classPtr) . '\\' . $className, '\\');
    foreach ($this->externalActions as $entry) {
      $entry = ltrim(trim($entry), '\\');
      if (strcasecmp($entry, str_contains($entry, '\\') ? $qualified : $className) === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
