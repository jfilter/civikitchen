<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A test class annotated `@coversNothing`, which silently records zero coverage.
 *
 * `@coversNothing` tells PHPUnit that a test contributes to no code's coverage.
 * civix's generated headless test template carries it at the class level, so a
 * suite scaffolded from that template runs green while measuring 0%.
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
        foreach ($context->tracked('*.php') as $file) {
            $source = $this->isTestFile($file) ? $context->read($file) : null;
            if ($source === null || stripos($source, 'coversNothing') === false) {
                continue;
            }
            foreach ($this->coversNothing($source) as $line) {
                $reporter->warnAt($file, $line, sprintf(
                    '%s:%d: @coversNothing / #[CoversNothing] makes this test count toward no coverage — civix ships it'
                    . ' in the headless test template; left in, the suite runs green while measuring 0%%',
                    $file,
                    $line,
                ));
            }
        }
    }

    /**
     * The annotation in a docblock, where PHPUnit reads it, or the attribute
     * in an attribute group; a plain comment mentioning it is neither.
     * Lines are where the docblock or attribute group starts.
     *
     * @return list<int>
     */
    private function coversNothing(string $source): array
    {
        $lines = [];
        // Bracket depth inside an attribute group; a name is the attribute's own
        // only right after `#[` or a top-level `,`, not inside its arguments.
        $depth = 0;
        $group = 0;
        $expectName = false;
        foreach (@\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_DOC_COMMENT) && preg_match('/@coversNothing(?![\w-])/', $token->text) === 1) {
                $lines[] = $token->line;
            }
            if ($token->is(T_ATTRIBUTE)) {
                [$depth, $group, $expectName] = [1, $token->line, true];
            } elseif ($depth === 0 || $token->isIgnorable()) {
                continue;
            } elseif ($token->is(['[', '('])) {
                $depth++;
            } elseif ($token->is([']', ')'])) {
                $depth--;
            } elseif ($depth === 1 && $token->is(',')) {
                $expectName = true;
            } elseif ($expectName && $depth === 1 && PhpSource::shortName($token) !== null) {
                $expectName = false;
                if (strcasecmp((string) PhpSource::shortName($token), 'CoversNothing') === 0) {
                    $lines[] = $group;
                }
            }
        }

        return $lines;
    }

    private function isTestFile(string $file): bool
    {
        if (!str_contains($file, '/tests/') && !str_starts_with($file, 'tests/')) {
            return false;
        }

        return str_ends_with($file, 'Test.php');
    }
}
