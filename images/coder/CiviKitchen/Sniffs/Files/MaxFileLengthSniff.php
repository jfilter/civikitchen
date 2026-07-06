<?php

declare(strict_types=1);

namespace CiviKitchen\Sniffs\Files;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Cap the physical line count of a single PHP file. PHP_CodeSniffer ships a line
 * WIDTH sniff (Generic.Files.LineLength) but nothing for overall file LENGTH, so
 * a runaway 2000-line class sails through every other check. An over-long file is
 * a maintainability smell — split it into focused classes.
 *
 * The cap is configurable from the consuming ruleset; the default is deliberately
 * generous so it only ever fires on genuinely oversized files:
 *
 *   <rule ref="CiviKitchen.Files.MaxFileLength">
 *     <properties><property name="maxLines" value="800"/></properties>
 *   </rule>
 *
 * The error is reported on line 1 (the open tag) since it is a whole-file fact.
 */
final class MaxFileLengthSniff implements Sniff {

  /**
   * Maximum allowed physical lines per file. Overridable via the ruleset.
   *
   * @var int
   */
  public $maxLines = 1000;

  /**
   * @return array<int, int|string>
   */
  public function register(): array {
    return [T_OPEN_TAG];
  }

  /**
   * @param int $stackPtr
   */
  public function process(File $phpcsFile, $stackPtr): int {
    $tokens = $phpcsFile->getTokens();
    // Line of the last token == the file's physical line count.
    $lines = (int) $tokens[$phpcsFile->numTokens - 1]['line'];
    $max = (int) $this->maxLines;

    if ($lines > $max) {
      $phpcsFile->addError(
        'File is %s lines long; the maximum is %s. Split it into focused classes.',
        $stackPtr,
        'TooLong',
        [$lines, $max]
      );
    }

    // A whole-file check: assessed on the first open tag, never re-run.
    return $phpcsFile->numTokens;
  }

}
