<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The .gitignore has to cover the artifacts this repo can actually produce.
 *
 * CommittedArtifactCheck punishes a tracked cache after the fact; this one stops
 * it being tracked in the first place. The difference is not academic: phpunit
 * writes .phpunit.result.cache next to its config on every run, so a `git add -A`
 * straight after a test run commits it — which is exactly how it got into
 * berlinnav, and how a tsconfig.tsbuildinfo rode along in an inflow merge.
 *
 * Only artifacts the repo can generate are demanded, so a PHP-only extension is
 * never nagged about node_modules. The counterpart rule lives in LockfileCheck:
 * a lockfile must NEVER be ignored. Ignore what a build regenerates, commit what
 * pins it.
 */
final class GitignoreCoverageCheck implements Check
{
    /**
     * pattern => [needle in .gitignore, what makes it relevant]
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const ARTIFACTS = [
        '.phpunit.result.cache' => [['.phpunit.result.cache'], 'phpunit'],
        'vendor/' => [['vendor'], 'composer'],
        'node_modules/' => [['node_modules'], 'npm'],
        '*.tsbuildinfo' => [['tsbuildinfo'], 'typescript'],
    ];

    public function name(): string
    {
        return 'gitignore-coverage';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo() || !$context->exists('.gitignore')) {
            // A missing .gitignore is GitignoreCheck's business, not ours.
            return;
        }

        $lines = $this->ignoreLines($context);
        $missing = [];

        foreach (self::ARTIFACTS as $pattern => [$needles, $producer]) {
            if (!$this->produces($context, $producer)) {
                continue;
            }
            foreach ($needles as $needle) {
                foreach ($lines as $line) {
                    if (str_contains($line, $needle)) {
                        continue 3;
                    }
                }
            }
            $missing[] = $pattern;
        }

        if ($missing !== []) {
            $reporter->fail(
                '.gitignore does not cover artifacts this repo produces: ' . implode(' ', $missing)
            );
        } else {
            $reporter->ok('.gitignore covers the artifacts this repo produces');
        }
    }

    /**
     * Whether the repo can generate a given artifact at all. Demanding an ignore
     * for something that can never appear is the kind of noise that teaches
     * people to stop reading the output.
     */
    private function produces(Context $context, string $producer): bool
    {
        return match ($producer) {
            'phpunit' => $context->exists('phpunit.xml.dist') || $context->exists('phpunit.xml'),
            'composer' => $context->exists('composer.json'),
            'npm' => $context->tracked('*package.json', static fn (string $f): bool
                => !str_contains($f, 'node_modules')) !== [],
            'typescript' => $context->tracked('tsconfig*.json') !== [],
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private function ignoreLines(Context $context): array
    {
        $lines = [];
        foreach (explode("\n", $context->read('.gitignore') ?? '') as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
