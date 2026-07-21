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
     * pattern => [representative paths git is asked about, what makes it relevant]
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const ARTIFACTS = ['.phpunit.result.cache', 'vendor/', 'node_modules/', '*.tsbuildinfo'];

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

        $missing = [];
        foreach (self::ARTIFACTS as $pattern) {
            $samples = $this->samplesFor($context, $pattern);
            if ($samples === []) {
                continue;
            }
            foreach ($samples as $sample) {
                if (!$this->isIgnored($context, $sample)) {
                    $missing[] = $pattern;
                    break;
                }
            }
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
     * Where this artifact would actually appear, if anywhere.
     *
     * Derived from the real manifest locations rather than assumed at the repo
     * root: npm creates node_modules beside the package.json that declared it,
     * and TypeScript writes its cache beside its tsconfig. Demanding a
     * root-level ignore for a repo whose frontend lives in frontend/ is a false
     * positive, and noise is how a checker teaches people to stop reading it.
     *
     * An empty list means the repo cannot produce the artifact at all.
     *
     * @return list<string>
     */
    private function samplesFor(Context $context, string $pattern): array
    {
        $dirs = static function (array $files): array {
            $out = [];
            foreach ($files as $file) {
                $dir = dirname($file);
                $out[$dir === '.' ? '' : $dir . '/'] = TRUE;
            }

            return array_keys($out);
        };

        switch ($pattern) {
            case '.phpunit.result.cache':
                return ($context->exists('phpunit.xml.dist') || $context->exists('phpunit.xml'))
                    ? ['.phpunit.result.cache'] : [];

            case 'vendor/':
                return $context->exists('composer.json') ? ['vendor/autoload.php'] : [];

            case 'node_modules/':
                $manifests = $context->tracked('*package.json', static fn (string $f): bool
                    => !str_contains($f, 'node_modules'));

                return array_map(
                    static fn (string $d): string => $d . 'node_modules/left-pad/package.json',
                    $dirs($manifests)
                );

            case '*.tsbuildinfo':
                return array_map(
                    static fn (string $d): string => $d . 'tsconfig.tsbuildinfo',
                    $dirs($context->tracked('tsconfig*.json'))
                );
        }

        return [];
    }

    /**
     * Whether git would ignore this path — asked of git, not guessed from the
     * file.
     *
     * The first cut looked for a substring in .gitignore. It therefore accepted
     * 'frontend/.tsbuildinfo', the exact broken pattern that let inflow track a
     * build cache and that this check was written to catch — and it accepted a
     * '!' negation, which does the opposite of ignoring. git resolves patterns,
     * precedence, negation and nested .gitignore files; nothing else does.
     */
    private function isIgnored(Context $context, string $path): bool
    {
        $command = 'git -C ' . escapeshellarg($context->root)
            . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null';
        exec($command, $output, $status);

        return $status === 0;
    }
}
