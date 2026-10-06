<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tool config that nothing in CI ever runs.
 *
 * A phpstan.neon.dist in a repo whose workflow never calls phpstan, a
 * phpunit.xml.dist no job invokes, a Playwright harness CI never touches: each
 * looks like coverage from the outside — the config is there, the badge is green.
 *
 * The check is deliberately indirect-aware (CiCommands): `ck ci`, the shared CI,
 * and an `npm test` or `composer test` count as running what they run.
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
        $reachable = CiCommands::reachable($context);
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
                if (CiCommands::runs($reachable, $token)) {
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
}
