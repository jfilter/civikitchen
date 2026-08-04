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
     * Repo policy from the optional `.ckconform`: KEY=VALUE, '#' comments.
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
                Policy::parse($this->read('.ckconform')),
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

    public function isTracked(string $relative): bool
    {
        return in_array(ltrim($relative, '/'), $this->trackedFiles(), true);
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
