<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Repository;

use CiviKitchen\Toolbelt\Process\Runner;

final class Files
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function isGitCheckout(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree'])['status'] === 0;
    }

    /** @param list<string> $extensions @param list<string> $scope @return list<string> */
    public function source(array $extensions, array $scope = [], bool $excludeGenerated = true, bool $includeUntracked = false): array
    {
        $patterns = array_map(static fn(string $extension): string => "*.{$extension}", $extensions);
        $flags = $includeUntracked ? ['--cached', '--others', '--exclude-standard'] : [];
        $result = $this->git(['ls-files', ...$flags, '--', ...$patterns]);
        if ($result['status'] !== 0) {
            return [];
        }
        $vendored = $this->vendoredPrefixes();
        $files = array_values(array_filter(preg_split('/\R/', trim($result['output'])) ?: [], function (string $file) use ($vendored, $excludeGenerated): bool {
            if ($file === '' || preg_match('#(^|/)(node_modules|vendor|dist|build|bower_components|packages|\.civikitchen-siblings)/#', $file) === 1
                || preg_match('/\.(min|bundle)\.(js|ts)$/', $file) === 1) {
                return false;
            }
            if ($excludeGenerated && (str_ends_with($file, '.civix.php') || preg_match('#(^|/)DAO/#', $file) === 1)) {
                return false;
            }
            foreach ($vendored as $prefix) {
                if ($file === $prefix || str_starts_with($file, $prefix . '/')) {
                    return false;
                }
            }
            return true;
        }));
        if ($scope !== []) {
            $scoped = $this->git(['ls-files', ...$flags, '--', ...$scope]);
            $allowed = array_flip(preg_split('/\R/', trim($scoped['output'])) ?: []);
            $files = array_values(array_filter($files, static fn(string $file): bool => isset($allowed[$file])));
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    public function changedPhp(): array
    {
        $changed = $this->git(['diff', '--name-only', '--diff-filter=d', 'HEAD', '--']);
        $new = $this->git(['ls-files', '--others', '--exclude-standard']);
        $files = preg_split('/\R/', trim($changed['output'] . "\n" . $new['output'])) ?: [];
        $files = array_values(array_unique(array_filter($files, static fn(string $file): bool => str_ends_with($file, '.php'))));
        sort($files);
        return $files;
    }

    /** @return list<string> */
    public function vendoredPrefixes(): array
    {
        $binary = is_executable($this->checkoutRoot . '/toolbelt/bin/ckconform')
            ? $this->checkoutRoot . '/toolbelt/bin/ckconform' : 'ckconform';
        $result = $this->runner->capture([$binary, '--policy', 'vendored_paths']);
        if ($result['status'] !== 0) {
            return [];
        }
        $prefixes = [];
        foreach (preg_split('/\R/', trim($result['output'])) ?: [] as $line) {
            $path = rtrim(explode(' -- ', $line, 2)[0], '/');
            if ($path !== '') {
                $prefixes[] = $path;
            }
        }
        return $prefixes;
    }

    /** @param list<string> $arguments @return array{status:int,output:string} */
    private function git(array $arguments): array
    {
        return $this->runner->capture(['git', '-c', 'safe.directory=' . (string) getcwd(), ...$arguments]);
    }
}
