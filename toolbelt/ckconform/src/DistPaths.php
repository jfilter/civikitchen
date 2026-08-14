<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * What a release archive leaves out: the development layer.
 *
 * The one copy of that list. `ckrelease` builds and verifies the zip from it,
 * reading it through `ckconform --dist-paths` the way every ck* tool reads
 * `.ckconform` through this tool — same reason (one owner per format), and here
 * a drifting second copy reaches production sites as dev files inside an
 * installed extension.
 *
 * The list is central rather than per-repo `.gitattributes export-ignore`,
 * because `.gitattributes` is template-managed: editing it turns every
 * conforming repo's drift job red and puts the list in as many copies as there
 * are repos. A repo declares its deviation in `.ckconform` instead —
 * `dist_exclude=` / `dist_include=`.
 *
 * Two names are deliberately absent. `vendor/`: a repo that commits it does so
 * because the site needs it at runtime. `dist/`: for a frontend-building
 * extension the committed build IS the shipped artifact.
 */
final class DistPaths
{
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
        '.gitattributes',
        '.gitignore',
        '.editorconfig',
        '.ckconform',
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
     * The central list ± this repo's `.ckconform`. An entry ending in `/` is
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
            if (self::problem($item) !== null) {
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
            $problem = self::problem($item);
            if ($problem !== null) {
                $problems[] = $problem;
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
            if ($path === $entry || str_starts_with($path, $entry . '/')) {
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
        $raw = $context->policyValue($key);
        if ($raw === null) {
            return [];
        }
        $items = [];
        foreach (explode(',', Policy::stripReason($raw)) as $item) {
            $item = (string) preg_replace('/\s+/', '', $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function problem(string $item): ?string
    {
        return str_starts_with($item, '/') || str_contains($item, '..')
            ? "dist_exclude must be a repo-relative path: {$item}"
            : null;
    }
}
