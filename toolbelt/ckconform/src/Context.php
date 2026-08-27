<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Everything a check may want to know about the extension under inspection,
 * parsed once and parsed properly.
 *
 * The bash predecessor read info.xml with sed, composer.json with a regex and
 * globbed for nested files with a fixed-depth pattern. Every one of those was
 * eventually wrong in a way that made a check pass silently — a missed
 * `<ext version="3.32">`, a `ang/afform/*.aff.html` two levels down, a
 * `@since` parse that BSD sed rejected. Structured formats are parsed with
 * structured parsers here, and that is most of the reason this is PHP now.
 */
final class Context
{
    private ?\SimpleXMLElement $infoXml = null;

    /** @var array<string, array<mixed>|null> */
    private array $json = [];

    /** @var list<string>|null */
    private ?array $trackedFiles = null;

    /** @var array<string, string>|null */
    private ?array $policy = null;

    public function __construct(
        public readonly string $root,
        public readonly ?string $coreDir = null,
    ) {
    }

    public function path(string $relative): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function read(string $relative): ?string
    {
        $file = $this->path($relative);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    /**
     * Concatenation of whichever of these files exist, for "config may live in
     * either name" cases (phpstan.neon.dist / phpstan.neon).
     */
    public function readAny(string ...$relatives): ?string
    {
        $parts = [];
        foreach ($relatives as $relative) {
            $contents = $this->read($relative);
            if ($contents !== null) {
                $parts[] = $contents;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    public function infoXml(): ?\SimpleXMLElement
    {
        if ($this->infoXml === null) {
            $raw = $this->read('info.xml');
            if ($raw === null) {
                return null;
            }
            $previous = libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($raw);
            libxml_use_internal_errors($previous);
            if ($parsed === false) {
                return null;
            }
            $this->infoXml = $parsed;
        }

        return $this->infoXml;
    }

    /**
     * The extension key from info.xml's key attribute (`org.example.myext`),
     * or null when there is no info.xml or no key.
     */
    public function extensionKey(): ?string
    {
        $info = $this->infoXml();
        if ($info === null) {
            return null;
        }
        $key = trim((string) ($info['key'] ?? ''));

        return $key === '' ? null : $key;
    }

    /**
     * The extension's own CamelCase-ish name: the last dot-segment of the key
     * (`org.example.myext` -> `myext`), used only as an "is this ours" hint.
     */
    public function shortName(): ?string
    {
        $key = $this->extensionKey();
        if ($key === null) {
            return null;
        }
        $parts = explode('.', $key);
        $last = (string) end($parts);

        return $last === '' ? null : $last;
    }

    /** The `<version>` declared in info.xml, '' when absent. */
    public function infoVersion(): string
    {
        $info = $this->infoXml();
        if ($info === null || !isset($info->version)) {
            return '';
        }

        return trim((string) $info->version);
    }

    /** The `<license>` declared in info.xml, '' when absent. */
    public function infoLicense(): string
    {
        $info = $this->infoXml();
        if ($info === null || !isset($info->license)) {
            return '';
        }

        return (string) $info->license;
    }

    /**
     * Raw mixin declarations from info.xml, e.g. 'mgd-php@1.0.0'. Callers that
     * want bare names strip the '@' suffix themselves.
     *
     * @return list<string>
     */
    public function declaredMixins(): array
    {
        $info = $this->infoXml();
        if ($info === null) {
            return [];
        }
        $mixins = [];
        foreach ($info->xpath('//mixins/mixin') ?: [] as $mixin) {
            $value = trim((string) $mixin);
            if ($value !== '') {
                $mixins[] = $value;
            }
        }

        return $mixins;
    }

    /**
     * The <ext> children of info.xml's <requires>, as trimmed extension keys.
     * Read via SimpleXML because attributes are legal on the element — a regex
     * once missed `<ext version="3.32">org.civicoop.civirules</ext>` — and
     * empty/whitespace-only elements are dropped: '' is not a key, and letting
     * it through makes an in_array() dependency test silently unmatchable.
     *
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        $info = $this->infoXml();
        if ($info === null) {
            return [];
        }
        $keys = [];
        foreach ($info->xpath('//requires/ext') ?: [] as $ext) {
            $key = trim((string) $ext);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<mixed>|null
     */
    public function json(string $relative): ?array
    {
        if (!array_key_exists($relative, $this->json)) {
            $raw = $this->read($relative);
            $decoded = $raw === null ? null : json_decode($raw, true);
            $this->json[$relative] = is_array($decoded) ? $decoded : null;
        }

        return $this->json[$relative];
    }

    /**
     * Repo policy from the optional `.ckconform`: KEY=VALUE, '#' comments,
     * over the organisation-wide defaults file CK_DEFAULT_POLICY names.
     * The mechanism is public (it ships in this image), the values are not —
     * they live in the consuming repo, so a private licence policy stays private.
     *
     * @return array<string, string>
     */
    public function policy(): array
    {
        if ($this->policy === null) {
            // First occurrence wins, which is the scalar view of Policy::parse.
            // The parser itself lives there because it has other readers now:
            // ckinit, and every ck* tool through `ckconform --policy-env`.
            $this->policy = array_map(
                static fn (array $values): string => $values[0],
                Policy::effective($this->read('.ckconform')),
            );
        }

        return $this->policy;
    }

    /** @var list<string> */
    private array $skippedChecks = [];

    /**
     * The checks this run never executed, because `ignore_checks=` in the
     * policy skipped them. Set by the runner, since only it knows the outcome
     * of that parse.
     *
     * SuppressionHygieneCheck needs it: an inline ignore for a skipped check
     * cannot be called unused, because nothing ever looked for the finding it
     * would have silenced.
     *
     * @param list<string> $names
     */
    public function skipChecks(array $names): void
    {
        $this->skippedChecks = array_values($names);
    }

    /** @return list<string> */
    public function skippedChecks(): array
    {
        return $this->skippedChecks;
    }

    public function policyValue(string $key): ?string
    {
        $value = $this->policy()[$key] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    public function isGitRepo(): bool
    {
        return $this->trackedFiles() !== [];
    }

    /**
     * Git-tracked files, repo-relative. Tracked rather than on-disk on purpose:
     * an untracked file cannot break anyone else's build.
     *
     * @return list<string>
     */
    public function trackedFiles(): array
    {
        if ($this->trackedFiles === null) {
            $this->trackedFiles = [];
            $output = $this->git(['ls-files', '-z']);
            if ($output !== null) {
                foreach (explode("\0", $output) as $file) {
                    if ($file !== '') {
                        $this->trackedFiles[] = $file;
                    }
                }
            }
        }

        return $this->trackedFiles;
    }

    /**
     * @param  callable(string): bool|null $filter
     * @return list<string>
     */
    public function tracked(string $glob, ?callable $filter = null): array
    {
        $matches = [];
        foreach ($this->trackedFiles() as $file) {
            if (!fnmatch($glob, $file) && !fnmatch($glob, basename($file))) {
                continue;
            }
            if ($filter !== null && !$filter($file)) {
                continue;
            }
            $matches[] = $file;
        }

        return $matches;
    }

    /**
     * Git-tracked files under a directory, by extension — the tracked-only twin
     * of findFiles().
     *
     * findFiles() walks the disk, which contradicts the rule the rest of this
     * class follows: an untracked local file cannot break anyone else's build,
     * so it must not decide a check. A repo check that asks "does this repo ship
     * X" has to read what the repo ships, i.e. what is committed. An empty
     * directory string means the whole tree.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public function trackedUnder(string $directory, array $extensions = []): array
    {
        $prefix = $directory === '' ? '' : rtrim($directory, '/') . '/';
        $found = [];
        foreach ($this->trackedFiles() as $file) {
            if ($prefix !== '' && !str_starts_with($file, $prefix)) {
                continue;
            }
            if ($extensions !== []) {
                $matched = false;
                foreach ($extensions as $extension) {
                    if (str_ends_with($file, $extension)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $found[] = $file;
        }
        sort($found);

        return $found;
    }

    /**
     * The shared reusable CI workflow, by the filename repos name in `uses:`.
     * A repo whose workflow delegates to it is running everything that workflow
     * runs — cklint, ckconform, phpstan, phpunit under ckcoverage — even though
     * none of those tokens appears in the repo's own thin caller.
     */
    public const SHARED_CI = 'extension-ci.yml';

    /**
     * Does any workflow hand CI off to the shared reusable workflow? The
     * workflow-scanning checks treat that as running the tools it runs, or every
     * migrated repo reads as a CI that runs nothing.
     */
    public function callsSharedCi(): bool
    {
        foreach ($this->workflows() as $workflow) {
            if (str_contains($this->read($workflow) ?? '', self::SHARED_CI)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shared reusable release workflow, by the filename repos name in
     * `uses:` — the release-side counterpart to SHARED_CI.
     */
    public const SHARED_RELEASE = 'extension-release.yml';

    /**
     * Does any workflow hand releasing off to the shared pipeline? A repo that
     * calls it produces a tagged, verified archive; one that does not offers a
     * consumer nothing but a branch.
     */
    public function callsSharedRelease(): bool
    {
        foreach ($this->workflows() as $workflow) {
            if (str_contains($this->read($workflow) ?? '', self::SHARED_RELEASE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The newest `v*` tag reachable from HEAD, null when there is none.
     *
     * Reachable, not "newest by version": what the release checks compare
     * against is the last release cut on this line of history. A checkout made
     * without tags answers null too, so callers ask isShallowClone() and treat
     * a bare null as "cannot tell" rather than "never released".
     */
    public function newestTag(): ?string
    {
        $tag = trim((string) $this->git(['describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*']));

        return $tag === '' ? null : $tag;
    }

    /**
     * A clone whose history is truncated — `actions/checkout` makes one by
     * default (depth 1). Anything reasoning about commits since a tag reports
     * itself unevaluated here instead of finding nothing.
     */
    public function isShallowClone(): bool
    {
        return trim((string) $this->git(['rev-parse', '--is-shallow-repository'])) === 'true';
    }

    /**
     * Commits in `<ref>..HEAD`, newest first, with committer date and the files
     * each touched. Null when git could not answer at all — an unreadable
     * history is not an empty one.
     *
     * Merge commits carry no file list (`--name-only` shows no diff for them),
     * which is right: the commits they merge are listed in their own right.
     *
     * @return list<array{hash: string, date: string, files: list<string>}>|null
     */
    public function commitsSince(string $ref): ?array
    {
        // Record-separated rather than one commit per line: a commit's file
        // list is lines too, and \x1e occurs in neither.
        $output = $this->git([
            '-c', 'core.quotePath=false',
            'log', '--format=%x1e%H %cI', '--name-only', $ref . '..HEAD',
        ]);
        if ($output === null) {
            return null;
        }

        $commits = [];
        foreach (explode("\x1e", $output) as $record) {
            $lines = explode("\n", trim($record, "\n"));
            $header = trim((string) array_shift($lines));
            if ($header === '') {
                continue;
            }
            [$hash, $date] = array_pad(explode(' ', $header, 2), 2, '');
            $commits[] = [
                'hash' => $hash,
                'date' => trim($date),
                'files' => array_values(array_filter(
                    array_map('trim', $lines),
                    static fn (string $file): bool => $file !== '',
                )),
            ];
        }

        return $commits;
    }

    public function isTracked(string $relative): bool
    {
        return in_array(ltrim($relative, '/'), $this->trackedFiles(), true);
    }

    /**
     * Does the repo ship this file? Tracked when in git (repo principle: an
     * uncommitted local file must not sway a verdict), on disk otherwise.
     */
    public function ships(string $relative): bool
    {
        return $this->isGitRepo() ? $this->isTracked($relative) : $this->exists($relative);
    }

    /**
     * Does the repo ship an own APIv4 entity class Civi/Api4/<Entity>.php?
     * Source files only: a fixture under tests/fixtures/Civi/Api4 is not shipped.
     */
    public function shipsApi4Entity(string $entity): bool
    {
        foreach ($this->sourceFiles('', ['.php']) as $file) {
            if (str_ends_with($file, 'Civi/Api4/' . $entity . '.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the repo ship anything under this directory? Tracked files when in
     * git; outside git (tarball, exported build) whatever is on disk.
     */
    public function hasShippedUnder(string $directory): bool
    {
        return $this->isGitRepo()
            ? $this->trackedUnder($directory) !== []
            : $this->findFiles($directory) !== [];
    }

    /** read(), but only for files the repo ships — see ships(). */
    public function readShipped(string $relative): ?string
    {
        return $this->ships($relative) ? $this->read($relative) : null;
    }

    /**
     * Whether git would ignore this path — asked of git, not guessed from
     * .gitignore. git resolves patterns, precedence, negation and nested
     * .gitignore files; nothing else does. Outside a git repo (exit 128) the
     * answer is "not ignored".
     */
    public function isIgnored(string $relative): bool
    {
        // Same safe.directory as git(): under CI's uid mismatch a bare call
        // fails with "dubious ownership", which would read as "not ignored".
        $command = 'git -c ' . escapeshellarg('safe.directory=' . rtrim($this->root, '/'))
            . ' -C ' . escapeshellarg($this->root)
            . ' check-ignore -q ' . escapeshellarg($relative) . ' 2>/dev/null';
        exec($command, $output, $status);

        return $status === 0;
    }

    /**
     * Shipped source files: tracked (or, outside git, on-disk) files under a
     * directory, by suffix, minus tests/, vendor/ and node_modules/ at any
     * depth — code that never runs on an install must not decide a check.
     *
     * @param  list<string>                $extensions
     * @param  callable(string): bool|null $filter
     * @return list<string>
     */
    public function sourceFiles(string $directory = '', array $extensions = [], ?callable $filter = null): array
    {
        $files = $this->isGitRepo()
            ? $this->trackedUnder($directory, $extensions)
            : $this->findFiles($directory, $extensions);

        return array_values(array_filter($files, static function (string $file) use ($filter): bool {
            foreach (['tests/', 'vendor/', 'node_modules/'] as $skip) {
                if (str_starts_with($file, $skip) || str_contains($file, '/' . $skip)) {
                    return false;
                }
            }

            return $filter === null || $filter($file);
        }));
    }

    /** tracked() filter for manifests outside any node_modules install. */
    public static function outsideNodeModules(string $file): bool
    {
        return !str_contains($file, 'node_modules');
    }

    /**
     * The compose stacks the repo ships, tracked only: an untracked local
     * override is nobody else's problem.
     *
     * @return list<string>
     */
    public function composeFiles(): array
    {
        $files = [];
        foreach ($this->trackedFiles() as $file) {
            if (preg_match('/^(docker-)?compose.*\.ya?ml$/', basename($file)) === 1) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Files under a directory, recursively — no fixed-depth globs.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public function findFiles(string $directory, array $extensions = []): array
    {
        $base = $this->path($directory);
        if (!is_dir($base)) {
            return [];
        }
        $found = [];
        // Pruned even in fallback mode: .git is never repo content, and
        // .civikitchen-siblings/ is where the shared CI checks out a sibling
        // extension — foreign code with its own CI, whose hooks and files must
        // not be judged as this repo's (CiviCRM's own scanner skips
        // dot-directories for the same reason).
        $directoryIterator = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                static fn (\SplFileInfo $file): bool => !($file->isDir()
                    && in_array($file->getFilename(), ['.git', '.civikitchen-siblings'], true)),
            )
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ($extensions !== []) {
                $matched = false;
                foreach ($extensions as $extension) {
                    if (str_ends_with($name, $extension)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $found[] = substr($file->getPathname(), strlen(rtrim($this->root, '/')) + 1);
        }
        sort($found);

        return $found;
    }

    /**
     * Workflow files, sorted, repo-relative.
     *
     * @return list<string>
     */
    public function workflows(): array
    {
        return array_values(array_filter(
            $this->findFiles('.github/workflows', ['.yml', '.yaml']),
            static fn (string $f): bool => true,
        ));
    }

    private function git(array $args): ?string
    {
        // safe.directory: in CI the checkout is owned by the runner user while
        // the tools run as www-data, and git then refuses the repo ("dubious
        // ownership") — which silently degraded every tracked-files check to
        // the disk-walk fallback. This tool only ever READS the repo, so
        // trusting the one directory it was pointed at is sound.
        $command = 'git -c ' . escapeshellarg('safe.directory=' . rtrim($this->root, '/'))
            . ' -C ' . escapeshellarg($this->root);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ($status === 0 && is_string($stdout)) ? $stdout : null;
    }
}
