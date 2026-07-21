<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A test class annotated `@coversNothing`, which silently records zero coverage.
 *
 * `@coversNothing` tells PHPUnit that a test contributes to no code's coverage.
 * civix's generated headless test template carries it at the class level, so a
 * suite scaffolded from that template runs green while measuring nothing: shuttle
 * read 0.00% over sixteen passing headless tests, and it took a second opinion to
 * find the four `@coversNothing` lines that caused it. Removing them: 0% ->
 * 71.87%.
 *
 * ckcoverage catches the symptom once a floor is set, but only says "below the
 * floor", not why — and a repo still bringing its coverage up has no floor yet,
 * which is exactly when this bites. This names the annotation, so the 0% is not a
 * mystery. It is a warning, not a failure: `@coversNothing` is legal PHPUnit and
 * occasionally deliberate, but in a CiviCRM extension's headless suite it is
 * almost always the template footgun left in by accident.
 */
final class CoversNothingCheck implements Check
{
    public function name(): string
    {
        return 'covers-nothing';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $offenders = [];
        foreach ($context->tracked('*.php') as $file) {
            if (!$this->isTestFile($file)) {
                continue;
            }
            $source = $context->read($file);
            if ($source !== null && str_contains($source, '@coversNothing')) {
                $offenders[] = $file;
            }
        }

        if ($offenders === []) {
            return;
        }

        $reporter->warn(
            '@coversNothing makes these tests count toward no coverage: ' . implode(', ', $offenders)
            . ' — civix ships it in the headless test template; left in, the suite runs green'
            . ' while measuring 0%'
        );
    }

    private function isTestFile(string $file): bool
    {
        if (!str_contains($file, '/tests/') && !str_starts_with($file, 'tests/')) {
            return false;
        }

        return str_ends_with($file, 'Test.php');
    }
}
