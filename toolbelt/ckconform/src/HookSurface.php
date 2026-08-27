<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The scan surface shared by the hook checks: which prefixes this extension
 * legitimately dispatches under, which files may declare hooks, and the global
 * functions a file defines. Extracted from HookDispatchNameCheck when
 * HookStyleCheck needed the identical three answers.
 */
final class HookSurface
{
    /**
     * Every prefix under which a hook may legitimately be declared: the `<file>`
     * from info.xml, the last segment of the `<key>`, and the basename of a
     * root-level `<shortname>.php` main file. All three normally coincide; when
     * they do not, any of them is plausible enough that failing would be noise.
     * The first entry is the canonical one, and the one messages should name.
     *
     * @return list<string>
     */
    public static function expectedPrefixes(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }

        $prefixes = [];
        $file = trim((string) ($info->file ?? ''));
        if ($file !== '') {
            $prefixes[] = $file;
        }
        $key = trim((string) ($info['key'] ?? ''));
        if ($key !== '') {
            $segments = explode('.', $key);
            $prefixes[] = (string) end($segments);
        }
        // The main file on disk, recognised by its civix twin: an extension whose
        // root holds both `foo.php` and `foo.civix.php` dispatches under `foo`,
        // whatever info.xml claims. Cheap, and it saves a false FAIL on a repo
        // whose info.xml `<file>` has drifted from the file that is loaded.
        foreach ($context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('') as $candidate) {
            if (preg_match('#^([A-Za-z0-9_]+)\.civix\.php$#', $candidate, $m) === 1
                && $context->exists($m[1] . '.php')
            ) {
                $prefixes[] = $m[1];
            }
        }

        return array_values(array_unique(array_filter(
            $prefixes,
            static fn (string $p): bool => preg_match('/^[A-Za-z0-9_]+$/', $p) === 1,
        )));
    }

    /**
     * Tracked *.php outside tests/ and vendor/. Generated civix glue declares
     * hooks whose prefix civix itself owns, and vendored code is nobody's to
     * rename here.
     *
     * @return list<string>
     */
    public static function candidates(Context $context): array
    {
        return $context->sourceFiles(
            '',
            ['.php'],
            static fn (string $file): bool => !str_ends_with($file, '.civix.php'),
        );
    }

    /**
     * The hook suffixes an extension directory dispatches — the hooks it
     * invents. Read from the dispatch sites themselves, because that is the
     * only place a hook is defined: `dispatch('hook_civicrm_x', …)` (the
     * listener-era form) and `CRM_Utils_Hook::singleton()->invoke([...], …,
     * 'civicrm_x')` (the classic form, whose last argument is the name).
     * Tests, vendored and generated code are skipped like everywhere else.
     *
     * @return list<string>
     */
    public static function dispatchedSuffixes(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !($file->isDir()
                && in_array($file->getFilename(), ['.git', 'vendor', 'node_modules', 'tests'], true)),
        ));
        $suffixes = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (!str_contains($contents, '_civicrm_')) {
                continue;
            }
            foreach (self::dispatchSites($contents) as $suffix) {
                $suffixes[$suffix] = true;
            }
        }
        $found = array_keys($suffixes);
        sort($found);

        return $found;
    }

    /**
     * Hook suffixes named at `dispatch(` (first argument) and `invoke(` (last
     * string argument) call sites of one file.
     *
     * @return list<string>
     */
    private static function dispatchSites(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $found = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== \T_STRING || !in_array($token[1], ['dispatch', 'invoke'], true)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === \T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '(') {
                continue;
            }
            $depth = 1;
            $first = null;
            $last = null;
            for ($k = $j + 1; $k < $n && $depth > 0; $k++) {
                $inner = $tokens[$k];
                if ($inner === '(' || $inner === '[') {
                    $depth++;
                } elseif ($inner === ')' || $inner === ']') {
                    $depth--;
                } elseif ($depth === 1 && is_array($inner) && $inner[0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $literal = substr($inner[1], 1, -1);
                    $first ??= $literal;
                    $last = $literal;
                }
            }
            $name = $token[1] === 'dispatch' ? $first : $last;
            if ($name !== null && preg_match('/^(?:hook_)?civicrm_([a-zA-Z]+)$/', $name, $m) === 1) {
                $found[] = $m[1];
            }
        }

        return $found;
    }

    /**
     * Top-level function names with their declaration line, by token scan.
     *
     * Brace depth is the whole trick: a `function` seen at depth 0 is global,
     * anything deeper is a method or a nested closure. Interpolation tokens
     * (`T_CURLY_OPEN`, `${`) open a brace that `}` closes, so they have to be
     * counted or a string like "{$a}" would unbalance the rest of the file.
     * A `function` followed by `(` is an anonymous function and has no name.
     *
     * @return array<string, int> function name => line
     */
    public static function globalFunctions(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $depth = 0;
        $names = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }

            if ($token[0] === \T_CURLY_OPEN || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if ($token[0] !== \T_FUNCTION || $depth !== 0) {
                continue;
            }

            // Skip whitespace/comments and a by-reference '&' to reach the name.
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (is_array($next) && $next[0] === \T_STRING && !str_starts_with($next[1], '_')) {
                    $names[$next[1]] = $next[2];
                }
                break;
            }
        }

        return $names;
    }
}
