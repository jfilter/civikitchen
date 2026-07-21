<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The template runs phpstan at level 10 with no baseline: a baseline freezes the
 * debt it was generated from, and a lower level makes the run look green while
 * whole classes of error are never asked about.
 *
 * phpstan.neon is NEON, not XML or JSON, so there is no structured parser to
 * reach for here — but the bash version grepped the raw file, which meant a
 * commented-out `level: 10` counted. Comments are stripped before matching, and
 * the level is matched as a whole token so `level: 100` is no longer mistaken
 * for level 10.
 */
final class PhpstanConfigCheck implements Check
{
    public function name(): string
    {
        return 'phpstan-config';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $hasDist = $context->exists('phpstan.neon.dist');
        $hasPlain = $context->exists('phpstan.neon');

        if (!$hasDist && !$hasPlain) {
            $reporter->fail('no phpstan.neon.dist');

            return;
        }

        if (!$hasDist) {
            $reporter->warn('phpstan.neon should be phpstan.neon.dist (local overrides stay untracked)');
        }

        $config = $this->stripComments($context->readAny('phpstan.neon.dist', 'phpstan.neon') ?? '');

        if (preg_match('/(?:^|[\s{,\[])level:\s*(?:10|max)(?![0-9A-Za-z_.\-])/m', $config) === 1) {
            $reporter->ok('phpstan at level 10');
        } else {
            $reporter->fail('phpstan below level 10 (template: level 10, no baseline)');
        }

        if (preg_match('/class\.notFound|function\.notFound/', $config) === 1) {
            $reporter->warn('phpstan has blanket notFound ignores — use the bootstrap instead');
        }

        if ($this->hasBaselineFile($context)) {
            $reporter->fail('phpstan baseline file present (template: none)');
        }

        $escaping = $this->escapingScanPaths($config);
        if ($escaping !== []) {
            $reporter->fail(
                'phpstan scans a path outside the repo: ' . implode(', ', $escaping)
                . ' — a `../sibling` exists on the laptop it was written on and nowhere else,'
                . ' so CI dies with "Scanned directory does not exist". If an optional'
                . ' extension supplies a symbol, mount it at a fixed container path or cross'
                . ' the boundary with an APIv4 call instead of a compile-time reference'
            );
        }
    }

    /**
     * scanFiles / scanDirectories entries that climb out of the repo with `..`.
     *
     * mailjet scanned `../mailhealth` for an interface a class implemented — a
     * sibling checkout that is real on a developer machine and absent in CI and
     * on every other machine, so phpstan had in fact never run there. The honest
     * forms are a fixed container path (herald scans
     * /var/www/html/ext/org.civicoop.civirules) or no cross-repo type edge at
     * all. This catches the `..` form before it reaches a red CI with a cryptic
     * message.
     *
     * @return list<string>
     */
    private function escapingScanPaths(string $config): array
    {
        $escaping = [];
        // A YAML list item under scanFiles:/scanDirectories: whose value starts
        // with `..`. NEON quotes are optional; strip them if present.
        if (preg_match_all('/^\s*-\s*[\'"]?(\.\.\/[^\'"\s]+)/m', $config, $matches) === false) {
            return [];
        }
        foreach ($matches[1] as $path) {
            $escaping[] = $path;
        }

        return array_values(array_unique($escaping));
    }

    /**
     * NEON comments run from an unquoted '#' to the end of the line. Only the
     * plain cases matter here — a '#' inside a quoted phpstan option is not a
     * thing that occurs in these configs.
     */
    private function stripComments(string $config): string
    {
        $lines = [];
        foreach (explode("\n", $config) as $line) {
            $lines[] = (string) preg_replace('/(^|\s)#.*$/', '$1', $line);
        }

        return implode("\n", $lines);
    }

    /** Mirrors `ls phpstan-baseline*` in the repo root. */
    private function hasBaselineFile(Context $context): bool
    {
        $entries = scandir($context->path('.'));
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 'phpstan-baseline')) {
                return true;
            }
        }

        return false;
    }
}
