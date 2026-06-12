<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Extension;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Bans legacy hook implementations where CiviCRM has standard mixins.
 *
 * Modern extensions should declare these features in info.xml and keep the
 * implementation in the conventional files that the mixin loads. That gives
 * civix a stable upgrade target and avoids custom hook boilerplate drifting
 * out of date.
 *
 * This intentionally only checks global extension hook functions. It does not
 * ban ordinary runtime hooks such as buildForm/post/pre because those remain
 * normal extension integration points.
 */
final class UseMixinsForStandardHooksSniff implements Sniff {

  /**
   * Legacy hook suffix => guidance shown in the message.
   *
   * The function name includes the extension prefix, e.g.
   * myext_civicrm_managed(). This map stores only the hook part.
   *
   * @var array<string, string>
   */
  public $legacyHooks = [
    'civicrm_managed' => 'move managed entity definitions to managed/*.mgd.php and enable the mgd-php mixin',
    'civicrm_navigationMenu' => 'move menu entries to xml/Menu/*.xml and enable the menu-xml mixin',
    'civicrm_alterSettingsMetaData' => 'move setting metadata to settings/*.setting.php and enable the setting-php mixin',
    'civicrm_entityTypes' => 'move entity type definitions to *.entityType.php and enable entity-types-php@2.0.0',
    'civicrm_angularModules' => 'move Angular module metadata to ang/*.ang.php and enable the ang-php mixin',
  ];

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_FUNCTION];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $tokens = $phpcsFile->getTokens();

    // Only global extension hook functions are in scope. Class methods,
    // closures and anonymous functions are not hook implementations.
    if ($tokens[$stackPtr]['conditions'] !== []) {
      return;
    }

    $functionName = $phpcsFile->getDeclarationName($stackPtr);
    if (!is_string($functionName) || $functionName === '') {
      return;
    }

    foreach ($this->legacyHooks as $hook => $guidance) {
      if (!$this->endsWith($functionName, '_' . $hook)) {
        continue;
      }

      $phpcsFile->addError(
        'Legacy hook %s() is banned in modern extensions — %s',
        $stackPtr,
        'LegacyHook',
        [$functionName, $guidance]
      );
      return;
    }
  }

  private function endsWith(string $value, string $suffix): bool {
    $length = strlen($suffix);
    if ($length === 0) {
      return TRUE;
    }
    return strcasecmp(substr($value, -$length), $suffix) === 0;
  }

}
