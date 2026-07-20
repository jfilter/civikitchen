<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Legacy;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Discourages new QuickForm/Smarty UI layers: classes extending
 * CRM_Core_Page or CRM_Core_Form (directly or via the common intermediate
 * bases) get a WARNING pointing at the declarative stack — SearchKit
 * SavedSearch/SearchDisplay for listings, Afform/FormBuilder for forms,
 * APIv4 actions for endpoints.
 *
 * A warning, not an error: existing extensions must keep linting green while
 * the legacy screens are migrated, and a few cases (raw callback endpoints,
 * iframe hosts) are legitimately page-based — silence those with
 * `// phpcs:ignore CiviKitchen.Legacy.NoLegacyPageForm`.
 *
 * The base-class list is a ruleset <property>, so a project can extend it
 * with its own legacy bases without editing the sniff.
 */
final class NoLegacyPageFormSniff implements Sniff {

  /**
   * Legacy base classes => guidance shown in the message.
   *
   * @var array<string, string>
   */
  public $legacyBaseClasses = [
    'CRM_Core_Page' => 'prefer a SearchKit display or an Afform page; use an APIv4 action for data endpoints',
    'CRM_Core_Form' => 'prefer Afform/FormBuilder (afformSubmit for custom handling)',
    'CRM_Core_Page_Basic' => 'prefer a SearchKit display with inline edit / task links',
    'CRM_Core_Form_Search' => 'prefer a SearchKit SavedSearch + SearchDisplay',
    'CRM_Report_Form' => 'prefer a SearchKit SavedSearch (reports are deprecated in core)',
  ];

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
    if ($extended === FALSE) {
      return;
    }
    $extended = ltrim($extended, '\\');
    if (isset($this->legacyBaseClasses[$extended])) {
      $phpcsFile->addWarning(
        'Class extends legacy UI base %s — %s',
        $stackPtr,
        'LegacyUiBase',
        [$extended, $this->legacyBaseClasses[$extended]]
      );
    }
  }

}
