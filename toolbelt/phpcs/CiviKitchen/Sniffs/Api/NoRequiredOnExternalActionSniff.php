<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Api;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

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
 */
final class NoRequiredOnExternalActionSniff implements Sniff {

  /**
   * Unqualified class names of the externally reachable actions to guard
   * (e.g. Intake, Confirm). Set per project in the ruleset; empty = inert.
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

    $classPtr = $phpcsFile->findNext([T_CLASS], 0);
    if ($classPtr === FALSE) {
      return;
    }
    $className = $phpcsFile->getDeclarationName($classPtr);
    if ($className === NULL || !in_array($className, $this->externalActions, TRUE)) {
      return;
    }

    $phpcsFile->addError(
      '@required is forbidden on the externally reachable action %s — enforce requiredness as a rejected verdict in the validation method, never as an APIv4 exception (a proxy HTTP 500 is retried forever)',
      $stackPtr,
      'RequiredOnExternalAction',
      [$className]
    );
  }

}
