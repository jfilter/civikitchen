<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Security;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Warns on an APIv4 call that switches the permission layer off.
 *
 * `->setCheckPermissions(FALSE)` and `'checkPermissions' => FALSE` run the call
 * as nobody: ACLs, permission-driven field filtering and the entity's own
 * permission map stop applying. That is exactly right in a system context — a
 * cron job, an upgrader step, a queue worker — and exactly wrong in anything
 * reachable by a browser request, where it turns an authenticated page into a
 * data exporter. Nothing in the code says which of the two this is, so the sniff
 * asks for the decision to be visible rather than banning it:
 *
 *     // phpcs:ignore CiviKitchen.Security.PermissionBypass -- upgrader, no user
 *     $result = Contact::get(FALSE)->execute();
 *
 * Deliberately narrow. It reads two literal forms only — the method call, and
 * the array key inside a `civicrm_api4()` call — and never guesses through a
 * variable: `$params['checkPermissions'] = $flag` is not decidable here, and a
 * warning that cannot be trusted is worse than no warning.
 *
 * Tests are excluded by the ruleset, not by this sniff: running as nobody is the
 * normal way to arrange test fixtures.
 */
final class PermissionBypassSniff implements Sniff {

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
    $tokens = $phpcsFile->getTokens();
    if ($tokens[$stackPtr]['code'] === T_STRING) {
      $this->processSetter($phpcsFile, $stackPtr);
      return;
    }
    $this->processArrayKey($phpcsFile, $stackPtr);
  }

  /**
   * `->setCheckPermissions(FALSE)` — a single FALSE literal argument.
   */
  private function processSetter(File $phpcsFile, int $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    if (strtolower($tokens[$stackPtr]['content']) !== 'setcheckpermissions') {
      return;
    }

    // A method call, not a declaration of the same name.
    $prev = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, NULL, TRUE);
    if ($prev === FALSE || !in_array($tokens[$prev]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], TRUE)) {
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

    // Exactly one argument, and that argument the FALSE literal. Anything
    // else — a variable, an expression, TRUE — is not this sniff's business.
    $argument = NULL;
    for ($i = $open + 1; $i < $close; $i++) {
      if ($tokens[$i]['code'] === T_WHITESPACE) {
        continue;
      }
      if ($argument !== NULL) {
        return;
      }
      $argument = $i;
    }
    if ($argument === NULL || strtolower($tokens[$argument]['content']) !== 'false') {
      return;
    }

    $this->warn($phpcsFile, $stackPtr, 'setCheckPermissions(FALSE)');
  }

  /**
   * `'checkPermissions' => FALSE` inside a civicrm_api4() call.
   */
  private function processArrayKey(File $phpcsFile, int $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    if (trim($tokens[$stackPtr]['content'], '"\'') !== 'checkPermissions') {
      return;
    }

    $arrow = $phpcsFile->findNext(T_WHITESPACE, $stackPtr + 1, NULL, TRUE);
    if ($arrow === FALSE || $tokens[$arrow]['code'] !== T_DOUBLE_ARROW) {
      return;
    }
    $value = $phpcsFile->findNext(T_WHITESPACE, $arrow + 1, NULL, TRUE);
    if ($value === FALSE || strtolower($tokens[$value]['content']) !== 'false') {
      return;
    }

    if (!$this->insideApi4Call($phpcsFile, $stackPtr)) {
      return;
    }

    $this->warn($phpcsFile, $stackPtr, "'checkPermissions' => FALSE");
  }

  /**
   * Is this token nested inside the parentheses of a civicrm_api4() call?
   */
  private function insideApi4Call(File $phpcsFile, int $stackPtr): bool {
    $tokens = $phpcsFile->getTokens();
    foreach (array_keys($tokens[$stackPtr]['nested_parenthesis'] ?? []) as $opener) {
      $before = $phpcsFile->findPrevious(T_WHITESPACE, $opener - 1, NULL, TRUE);
      if ($before !== FALSE
        && $tokens[$before]['code'] === T_STRING
        && strtolower($tokens[$before]['content']) === 'civicrm_api4') {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function warn(File $phpcsFile, int $stackPtr, string $form): void {
    $phpcsFile->addWarning(
      '%s runs the call without ACLs or permission checks; legitimate in a system context, but make it deliberate — annotate with `// phpcs:ignore CiviKitchen.Security.PermissionBypass -- <reason>`',
      $stackPtr,
      'PermissionBypass',
      [$form]
    );
  }

}
