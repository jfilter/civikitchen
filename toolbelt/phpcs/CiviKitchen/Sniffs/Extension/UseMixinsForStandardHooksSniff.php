<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Extension;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\Namespaces;

/**
 * Bans legacy hook implementations where CiviCRM has standard mixins.
 *
 * Modern extensions should declare these features in info.xml and keep the
 * implementation in the conventional files that the mixin loads. That gives
 * civix a stable upgrade target and avoids custom hook boilerplate drifting
 * out of date.
 *
 * A function is a hook implementation when its name is `<file>_<hook>`
 * (case-insensitive), `<file>` taken from the nearest info.xml above the
 * linted file. Without an info.xml (or its `<file>`) any function name ending
 * in `_<hook>` counts. Global functions and functions declared inside an `if`
 * (the function_exists() guard) are checked; methods and closures are not.
 *
 * This intentionally only covers hooks a mixin replaces. Ordinary runtime
 * hooks such as buildForm/post/pre or alterSettingsMetaData remain normal
 * extension integration points.
 */
final class UseMixinsForStandardHooksSniff implements Sniff {

  /**
   * Legacy hook => guidance shown in the message.
   *
   * The function name includes the extension prefix, e.g.
   * myext_civicrm_managed(). This map stores only the hook part.
   *
   * @var array<string, string>
   */
  public $legacyHooks = [
    'civicrm_managed' => 'move managed entity definitions to managed/*.mgd.php and enable the mgd-php mixin',
    'civicrm_navigationMenu' => 'declare menu entries as managed Navigation records in managed/*.mgd.php and enable the mgd-php mixin',
    'civicrm_xmlMenu' => 'move routes to xml/Menu/*.xml and enable the menu-xml mixin',
    'civicrm_caseTypes' => 'move case types to xml/case/*.xml and enable the case-xml mixin',
    'civicrm_themes' => 'move theme definitions to *.theme.php and enable the theme-php mixin',
    'civicrm_alterSettingsFolders' => 'move setting metadata to settings/*.setting.php and enable the setting-php mixin',
    'civicrm_entityTypes' => 'move entity type definitions to *.entityType.php and enable entity-types-php@2.0.0',
    'civicrm_angularModules' => 'move Angular module metadata to ang/*.ang.php and enable the ang-php mixin',
  ];

  /**
   * Directory => the `<file>` of the nearest info.xml at or above it (NULL: none).
   *
   * @var array<string, string|null>
   */
  private static array $prefixByDir = [];

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
    foreach ($tokens[$stackPtr]['conditions'] as $condition) {
      if ($condition !== T_IF && $condition !== T_NAMESPACE) {
        return;
      }
    }
    // Core calls only the global function.
    if (Namespaces::determineNamespace($phpcsFile, $stackPtr) !== '') {
      return;
    }

    $functionName = $phpcsFile->getDeclarationName($stackPtr);
    if (!is_string($functionName) || $functionName === '') {
      return;
    }

    $prefix = self::extensionPrefix(dirname($phpcsFile->getFilename()));
    foreach ($this->legacyHooks as $hook => $guidance) {
      $matches = $prefix === NULL
        ? strcasecmp(substr($functionName, -strlen($hook) - 1), '_' . $hook) === 0
        : strcasecmp($functionName, $prefix . '_' . $hook) === 0;
      if (!$matches) {
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

  private static function extensionPrefix(string $dir): ?string {
    if (array_key_exists($dir, self::$prefixByDir)) {
      return self::$prefixByDir[$dir];
    }
    $prefix = NULL;
    if (is_file($dir . '/info.xml')) {
      $internalErrors = libxml_use_internal_errors(TRUE);
      $xml = simplexml_load_file($dir . '/info.xml', 'SimpleXMLElement', LIBXML_NONET);
      libxml_clear_errors();
      libxml_use_internal_errors($internalErrors);
      $file = $xml === FALSE ? '' : trim((string) $xml->file);
      $prefix = $file === '' ? NULL : $file;
    }
    elseif (dirname($dir) !== $dir) {
      $prefix = self::extensionPrefix(dirname($dir));
    }

    return self::$prefixByDir[$dir] = $prefix;
  }

}
