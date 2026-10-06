<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tracked manifest without a tracked lockfile means nobody can reproduce a
 * build — least of all the CI run that shipped the bundle.
 *
 * Three sub-rules, all git-only:
 *  - every tracked package.json that declares dependencies needs a tracked
 *    lockfile next to it, unless it is a workspace member, whose lockfile is the
 *    workspace root's (npm/yarn `workspaces`, pnpm-workspace.yaml);
 *  - a composer.json that declares real dependencies (platform packages such
 *    as php and ext-* are none) needs a tracked composer.lock;
 *  - where a lockfile is required, .gitignore must not exclude it, which would
 *    make it untrackable no matter how careful anyone is afterwards.
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

        foreach ($this->lockedManifests($context) as $manifest) {
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
     * .gitignore files are resolved the way git resolves them.
     *
     * @return list<string>
     */
    private function ignoredLockfiles(Context $context): array
    {
        $dirs = ['js' => [], 'composer' => []];
        foreach ($this->lockedManifests($context) as $manifest) {
            $dirs['js'][] = $this->directoryOf($manifest);
        }
        foreach ($context->tracked('composer.json', Context::outsideNodeModules(...)) as $manifest) {
            if ($this->composerDeclaresDependencies($context, $manifest)) {
                $dirs['composer'][] = $this->directoryOf($manifest);
            }
        }

        $ignored = [];
        foreach (self::IGNORABLE_LOCKFILES as $name) {
            foreach (array_unique($dirs[$name === 'composer.lock' ? 'composer' : 'js']) as $dir) {
                if ($context->isIgnored($dir . $name)) {
                    $ignored[] = $dir . $name;
                    break;
                }
            }
        }

        return $ignored;
    }

    private function directoryOf(string $manifest): string
    {
        $dir = dirname($manifest);

        return $dir === '.' ? '' : $dir . '/';
    }

    /**
     * The package.json files that need a lockfile beside them: those that
     * declare dependencies or workspaces, minus workspace members.
     *
     * @return list<string>
     */
    private function lockedManifests(Context $context): array
    {
        $manifests = $context->tracked('package.json', Context::outsideNodeModules(...));
        $patterns = [];
        foreach ($manifests as $manifest) {
            $workspaces = $context->json($manifest)['workspaces'] ?? [];
            $workspaces = is_array($workspaces['packages'] ?? null) ? $workspaces['packages'] : $workspaces;
            foreach (is_array($workspaces) ? $workspaces : [] as $pattern) {
                $patterns[] = [$this->directoryOf($manifest), $pattern];
            }
        }
        foreach ($context->tracked('pnpm-workspace.yaml') as $file) {
            $packages = Policy::parseYaml($context->read($file) ?? '')['packages'] ?? [];
            foreach (is_array($packages) ? $packages : [] as $pattern) {
                $patterns[] = [$this->directoryOf($file), $pattern];
            }
        }

        return array_values(array_filter(
            $manifests,
            fn (string $manifest): bool => $this->declaresPackages($context, $manifest)
                && !$this->isWorkspaceMember($manifest, $patterns),
        ));
    }

    private function declaresPackages(Context $context, string $manifest): bool
    {
        $json = $context->json($manifest);
        if ($json === null) {
            return true;
        }
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'workspaces'] as $key) {
            if (($json[$key] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: string, 1: mixed}> $patterns workspace root directory and glob, `!` negating */
    private function isWorkspaceMember(string $manifest, array $patterns): bool
    {
        $member = false;
        foreach ($patterns as [$root, $pattern]) {
            $directory = dirname($manifest);
            if (!is_string($pattern) || $root === $this->directoryOf($manifest) || !str_starts_with($directory . '/', $root)) {
                continue;
            }
            $negated = str_starts_with($pattern, '!');
            $glob = rtrim(preg_replace('#^(\./)+#', '', ltrim($pattern, '!')) ?? '', '/');
            if (fnmatch($glob, substr($directory, strlen($root)))) {
                $member = !$negated;
            }
        }

        return $member;
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
        return $context->isTracked('composer.json')
            && $this->composerDeclaresDependencies($context, 'composer.json')
            && !$context->isTracked('composer.lock');
    }

    /** Whether `require` names a real package; platform packages (getcomposer.org/doc/01-basic-usage.md#platform-packages) install nothing. */
    private function composerDeclaresDependencies(Context $context, string $manifest): bool
    {
        $require = $context->json($manifest)['require'] ?? null;
        foreach (is_array($require) ? array_keys($require) : [] as $package) {
            if (preg_match('/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer(?:-plugin-api|-runtime-api)?)$/i', (string) $package) !== 1) {
                return true;
            }
        }

        return false;
    }

}
