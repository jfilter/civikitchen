<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * What a release archive leaves out: the development layer.
 *
 * The one copy of that list. `ckrelease` builds and verifies the zip from it,
 * reading it through `ckconform --dist-paths` the way every ck* tool reads
 * `civikitchen.yaml` through this tool — same reason (one owner per format), and here
 * a drifting second copy reaches production sites as dev files inside an
 * installed extension.
 *
 * The list is central rather than per-repo `.gitattributes export-ignore`,
 * because `.gitattributes` is template-managed: editing it turns every
 * conforming repo's drift job red and puts the list in as many copies as there
 * are repos. A repo declares its deviation in `policy.dist` instead.
 *
 * Two names are deliberately absent. `vendor/`: a repo that commits it does so
 * because the site needs it at runtime. `dist/`: for a frontend-building
 * extension the committed build IS the shipped artifact.
 */
final class DistPaths
{
    /** Paths which policy may never put into a release archive. */
    public const PROTECTED_PATHS = [
        'civikitchen.yaml',
        '.git',
        '.github',
        '.env',
        '.env.*',
        '.envrc',
        '.netrc',
        '.npmrc',
        '.pypirc',
        'auth.json',
        'credentials.json',
    ];

    /**
     * Directory-shaped entries. The build excludes them at the repo root, which
     * is where the template puts them; `ckrelease verify` additionally rejects
     * them as a path segment at any depth.
     *
     * @var list<string>
     */
    public const DIRS = [
        '.github',
        '.docker',
        '.claude',
        'tests',
        'node_modules',
    ];

    /** @var list<string> */
    public const FILES = [
        ...self::PROTECTED_PATHS,
        '.gitattributes',
        '.gitignore',
        '.editorconfig',
        'civikitchen.yaml',
        'renovate.json',
        '.phpunit.result.cache',
        'phpcs.xml',
        'phpcs.xml.dist',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstanBootstrap.php',
        'phpunit.xml',
        'phpunit.xml.dist',
        'playwright.config.ts',
        'package-lock.json',
        'bun.lock',
        'bun.lockb',
        'tsconfig.json',
    ];

    /**
     * The central list adjusted by this repo's civikitchen.yaml policy.
     * directory-shaped, everything else is a file name — which is the only
     * thing the two lists differ in, since both become the same `git archive`
     * pathspec.
     *
     * @return array{dirs: list<string>, files: list<string>}
     */
    public static function excluded(Context $context): array
    {
        $dirs = self::DIRS;
        $files = self::FILES;

        foreach (self::listValue($context, 'dist_exclude') as $item) {
            if (self::problem($item, 'dist.exclude') !== null) {
                continue;
            }
            if (str_ends_with($item, '/')) {
                $dirs[] = rtrim($item, '/');
            } else {
                $files[] = $item;
            }
        }

        foreach (self::listValue($context, 'dist_include') as $keep) {
            $keep = rtrim($keep, '/');
            if (self::isProtected($keep)) continue;
            $dirs = array_values(array_filter($dirs, static fn (string $d): bool => $d !== $keep));
            $files = array_values(array_filter($files, static fn (string $f): bool => $f !== $keep));
        }

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Complaints about the declared values, for the caller that has to refuse
     * to build rather than ship: a path leaving the repo excludes nothing here
     * and something else wherever it does resolve.
     *
     * @return list<string>
     */
    public static function problems(Context $context): array
    {
        $problems = [];
        foreach (self::listValue($context, 'dist_exclude') as $item) {
            $problem = self::problem($item, 'dist.exclude');
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        foreach (self::listValue($context, 'dist_include') as $item) {
            $problem = self::problem($item, 'dist.include');
            if ($problem !== null) $problems[] = $problem;
            if (self::isProtected(rtrim($item, '/'))) {
                $problems[] = "dist.include may not ship protected development or secret-bearing path: {$item}";
            }
        }

        return $problems;
    }

    /**
     * Would this repo-relative path land in the archive? Root-anchored, like
     * the build: a nested `sub/tests/` ships (and `verify` then rejects the
     * archive, which is the finding that belongs to `verify`).
     *
     * File entries are prefix-matched too — a `dist_exclude=build` without a
     * trailing slash still excludes the whole directory as a git pathspec.
     *
     * @param array{dirs: list<string>, files: list<string>} $excluded
     */
    public static function ships(string $path, array $excluded): bool
    {
        foreach ([...$excluded['dirs'], ...$excluded['files']] as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/') || (strpbrk($entry, '*?[') !== false && fnmatch($entry, $path, FNM_PATHNAME))) {
                return false;
            }
        }

        return true;
    }

    /**
     * One comma-separated policy value, reason stripped and whitespace removed
     * — the same shape `ckrelease` read before this became the owner.
     *
     * @return list<string>
     */
    private static function listValue(Context $context, string $key): array
    {
        $items = [];
        foreach ($context->policyValues($key) as $raw) {
            $item = $key === 'dist_include' ? Policy::stripReason($raw) : $raw;
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function isProtected(string $path): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($path === $protected || (strpbrk($protected, '*?[') !== false && fnmatch($protected, $path, FNM_PATHNAME))) return true;
        }
        return false;
    }

    private static function problem(string $item, string $key): ?string
    {
        if ($item === '' || str_starts_with($item, '/') || str_starts_with($item, ':') || str_contains($item, '\\') || preg_match('/[\x00-\x1F\x7F]/', $item)) {
            return "{$key} must be a safe repo-relative path: {$item}";
        }
        foreach (explode('/', rtrim($item, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return "{$key} must be a safe repo-relative path: {$item}";
        }
        return null;
    }
}
