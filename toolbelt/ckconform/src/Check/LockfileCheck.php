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

    private const IGNORABLE_LOCKFILES = ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'composer.lock'];

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

        foreach ($this->ignoredLockfiles($context) as $path) {
            $reporter->fail(".gitignore excludes {$path} — lockfiles belong in the repo");
        }
    }

    /**
     * Lockfile paths git would ignore, asked per location a lockfile can be
     * required: next to each tracked manifest and at the root. Git answers via
     * check-ignore (Context::isIgnored), so `*.lock`, negations and nested
     * .gitignore files are resolved the way git resolves them — the first cut
     * matched lines by suffix and read `!build/composer.lock` as an ignore.
     *
     * @return list<string>
     */
    private function ignoredLockfiles(Context $context): array
    {
        $dirs = [''];
        $manifests = array_merge(
            $this->manifests($context),
            $context->tracked('composer.json', Context::outsideNodeModules(...)),
        );
        foreach ($manifests as $manifest) {
            $dir = dirname($manifest);
            if ($dir !== '.') {
                $dirs[] = $dir . '/';
            }
        }

        $ignored = [];
        foreach (self::IGNORABLE_LOCKFILES as $name) {
            foreach (array_unique($dirs) as $dir) {
                if ($context->isIgnored($dir . $name)) {
                    $ignored[] = $dir . $name;
                    break;
                }
            }
        }

        return $ignored;
    }

    /** @return list<string> */
    private function manifests(Context $context): array
    {
        return $context->tracked('package.json', Context::outsideNodeModules(...));
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

}
