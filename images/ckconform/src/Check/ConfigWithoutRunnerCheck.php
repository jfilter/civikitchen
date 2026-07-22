<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tool config that nothing in CI ever runs.
 *
 * The generic form of a failure that turned up six separate ways in one audit:
 * a phpstan.neon.dist in a repo whose workflow never calls phpstan; 54 PHPUnit
 * files and a phpunit.xml.dist that no job invokes; a second phpunit config only
 * one repo remembers to run; a whole checks/ harness with Playwright specs and a
 * typecheck that CI never touches. Each looked like coverage from the outside —
 * the config is there, the tests are there, the badge is green.
 *
 * The check is deliberately indirect-aware: a workflow that runs `npm run test`
 * counts as running whatever that script maps to, because that is how these
 * repos actually wire their front ends.
 */
final class ConfigWithoutRunnerCheck implements Check
{
    /**
     * config glob => [runner tokens that count as invoking it, human name]
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const CONFIGS = [
        // Either runner invokes the CiviKitchen standard — repos split between
        // calling phpcs directly and the cklint wrapper, and both count.
        'phpcs.xml.dist' => [['phpcs', 'cklint'], 'phpcs'],
        'phpstan.neon.dist' => [['phpstan'], 'phpstan'],
        'phpstan.neon' => [['phpstan'], 'phpstan'],
        'phpunit.xml.dist' => [['phpunit', 'ckcoverage'], 'phpunit'],
        'phpunit-unit.xml.dist' => [['phpunit-unit', 'ckcoverage'], 'phpunit'],
        'playwright.config.ts' => [['playwright', 'npx playwright'], 'playwright'],
        'playwright.config.js' => [['playwright', 'npx playwright'], 'playwright'],
        'vitest.config.ts' => [['vitest'], 'vitest'],
        'vitest.config.js' => [['vitest'], 'vitest'],
    ];

    public function name(): string
    {
        return 'config-without-runner';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }
        $reachable = $this->ciReachableText($context);
        if ($reachable === '') {
            // No workflows at all is CiWorkflowCheck's finding, not ours.
            return;
        }

        $orphans = [];
        foreach (self::CONFIGS as $config => [$tokens, $tool]) {
            if (!$context->isTracked($config)) {
                continue;
            }
            $found = false;
            foreach ($tokens as $token) {
                if (str_contains($reachable, $token)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $orphans[] = $config . ' (no ' . $tool . ' step)';
            }
        }

        if ($orphans !== []) {
            $reporter->fail('config present but never run in CI: ' . implode(', ', $orphans));
        } else {
            $reporter->ok('every tool config has a CI step that runs it');
        }
    }

    /**
     * Everything CI can reach: the workflow files, plus the package.json scripts
     * those workflows invoke by name.
     *
     * Without the indirection a repo that runs `npm run test` would read as
     * having no vitest step, which is the kind of false positive that teaches
     * people to ignore the output.
     */
    private function ciReachableText(Context $context): string
    {
        $text = '';
        foreach ($context->workflows() as $workflow) {
            $text .= $this->withoutComments($context->read($workflow) ?? '') . "\n";
        }
        if ($text === '') {
            return '';
        }

        // Delegating to the shared CI runs phpcs, phpstan and phpunit — but not
        // playwright or vitest, which stay in the repo's own workflows. Add only
        // the tokens the shared workflow actually invokes, so a playwright config
        // it does not run is still correctly flagged.
        if ($context->callsSharedCi()) {
            $text .= ' cklint phpcs phpstan phpunit ckcoverage ';
        }

        preg_match_all('/(?:npm|yarn|pnpm|bun)\s+run\s+([A-Za-z0-9:_-]+)/', $text, $matches);
        $wanted = array_unique($matches[1]);
        if ($wanted === []) {
            return $text;
        }

        foreach ($context->tracked('*package.json', static fn (string $f): bool
            => !str_contains($f, 'node_modules')) as $manifest) {
            $scripts = $context->json($manifest)['scripts'] ?? null;
            if (!is_array($scripts)) {
                continue;
            }
            foreach ($wanted as $name) {
                $body = $scripts[$name] ?? null;
                if (is_string($body)) {
                    $text .= $body . "\n";
                }
            }
        }

        return $text;
    }

    /**
     * Workflow text with YAML comments removed.
     *
     * Matching the raw file means a step described in a comment counts as a step
     * that runs — inflow's test.yml explains at length why its phpunit job was
     * retired, and that explanation alone satisfied a naive search for
     * "phpunit". This is the third variant of the same mistake found in one day,
     * so it is worth stating plainly: never match a tool name against text that
     * still contains prose.
     *
     * Line-based and therefore approximate (a '#' inside a quoted string is
     * dropped too), which errs toward reporting a missing runner rather than
     * inventing one — the safe direction for this rule.
     */
    private function withoutComments(string $yaml): string
    {
        $out = [];
        foreach (explode("\n", $yaml) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            $out[] = preg_replace('/\s+#.*$/', '', $line) ?? $line;
        }

        return implode("\n", $out);
    }
}
