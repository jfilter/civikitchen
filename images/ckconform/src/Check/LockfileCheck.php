<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tracked manifest without a tracked lockfile means nobody can reproduce a
 * build — least of all the CI run that shipped the bundle.
 *
 * Three sub-rules, all git-only:
 *  - every tracked package.json needs a tracked lockfile next to it;
 *  - a composer.json that declares real dependencies needs a tracked
 *    composer.lock (parsed via Context::json(), not sed'ed out of the file);
 *  - none of the lockfile names may be pushed into .gitignore, which would
 *    make them untrackable no matter how careful anyone is afterwards.
 */
final class LockfileCheck implements Check
{
    private const JS_LOCKFILES = ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'];

    private const IGNORABLE_LOCKFILES = ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'composer.lock'];

    public function name(): string
    {
        return 'lockfile';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach ($this->manifests($context) as $manifest) {
            if (!$this->hasLockfile($context, $manifest)) {
                $reporter->fail("{$manifest} has no tracked lockfile (builds are unreproducible)");
            }
        }

        if ($this->composerDeclaresUntrackedLock($context)) {
            $reporter->fail('composer.json declares dependencies but composer.lock is not tracked');
        }

        foreach (self::IGNORABLE_LOCKFILES as $name) {
            if ($this->gitignoreExcludes($context, $name)) {
                $reporter->fail(".gitignore excludes {$name} — lockfiles belong in the repo");
            }
        }
    }

    /** @return list<string> */
    private function manifests(Context $context): array
    {
        return $context->tracked(
            '*package.json',
            static fn (string $file): bool => !str_contains($file, 'node_modules'),
        );
    }

    private function hasLockfile(Context $context, string $manifest): bool
    {
        $dir = dirname($manifest);
        foreach (self::JS_LOCKFILES as $lock) {
            $candidate = $dir === '.' ? $lock : "{$dir}/{$lock}";
            if ($context->isTracked($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function composerDeclaresUntrackedLock(Context $context): bool
    {
        if (!$context->isTracked('composer.json')) {
            return false;
        }

        $composer = $context->json('composer.json');
        $require = (is_array($composer) && is_array($composer['require'] ?? null))
            ? $composer['require']
            : [];
        unset($require['php']);

        return $require !== [] && !$context->isTracked('composer.lock');
    }

    /**
     * Matches a .gitignore line that is exactly the name, or ends with
     * "/{$name}" — the same two shapes the bash predecessor's regex matched.
     * Comment lines are skipped, which the bash regex did not do: a `#`-led
     * line ending in "/composer.lock" satisfied its unanchored alternative
     * too. See PORTING NOTES for details.
     */
    private function gitignoreExcludes(Context $context, string $name): bool
    {
        $contents = $context->read('.gitignore');
        if ($contents === null) {
            return false;
        }

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($line === $name || str_ends_with($line, "/{$name}")) {
                return true;
            }
        }

        return false;
    }
}
