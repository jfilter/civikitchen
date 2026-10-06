<?php

declare(strict_types=1);

namespace CiviKitchen\Util;

use PHP_CodeSniffer\Files\File;
use PHPCSUtils\Utils\Namespaces;
use PHPCSUtils\Utils\UseStatements;

/**
 * Class-name resolution shared by the CiviKitchen sniffs.
 */
final class Names {

  /**
   * The fully qualified form (no leading `\`) of a class name as written at
   * $ptr, resolved against the namespace and the import `use` statements
   * above it.
   */
  public static function resolveClass(File $phpcsFile, int $ptr, string $name): string {
    if (str_starts_with($name, '\\')) {
      return ltrim($name, '\\');
    }
    $namespace = Namespaces::determineNamespace($phpcsFile, $ptr);
    $parts = explode('\\', $name, 2);
    $imports = self::classImports($phpcsFile, $ptr, $namespace);
    if (isset($imports[strtolower($parts[0])])) {
      return $imports[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '');
    }

    return ltrim($namespace . '\\' . $name, '\\');
  }

  /**
   * Lower-case alias => imported class of the `use` statements before $ptr in
   * the same namespace.
   *
   * @return array<string, string>
   */
  private static function classImports(File $phpcsFile, int $ptr, string $namespace): array {
    $imports = [];
    $use = 0;
    while (($use = $phpcsFile->findNext(T_USE, $use + 1, $ptr)) !== FALSE) {
      if (!UseStatements::isImportUse($phpcsFile, $use) || Namespaces::determineNamespace($phpcsFile, $use) !== $namespace) {
        continue;
      }
      foreach (UseStatements::splitImportUseStatement($phpcsFile, $use)['name'] as $alias => $class) {
        $imports[strtolower((string) $alias)] = $class;
      }
    }

    return $imports;
  }

}
