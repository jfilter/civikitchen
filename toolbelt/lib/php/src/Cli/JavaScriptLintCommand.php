<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\JavaScript\Api4CatalogExport;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;

final class JavaScriptLintCommand implements Command
{
    private const IGNORED_PATTERNS = ['**/node_modules/**', '**/vendor/**', '**/dist/**', '**/build/**', '**/.civikitchen-siblings/**',
        '**/bower_components/**', '**/packages/**', '**/*.min.js', '**/*.bundle.js'];

    /** Path segments of core's vendored and test trees, skipped by --core. */
    private const CORE_SKIPPED_DIRECTORIES = ['node_modules', 'vendor', 'bower_components', 'packages', 'dist', 'build', 'extern', 'tests'];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $fix = false;
        $core = false;
        $format = '';
        $paths = [];
        $literal = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && $argument === '--') {
                $literal = true;
            } elseif (!$literal && $argument === '--fix') {
                $fix = true;
            } elseif (!$literal && $argument === '--core') {
                $core = true;
            } elseif (!$literal && $argument === '--format') {
                $format = (string) (array_shift($arguments) ?? '');
            } elseif (!$literal && str_starts_with($argument, '--format=')) {
                $format = substr($argument, 9);
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        $toolchain = is_executable('/opt/civikitchen-oxlint/node_modules/.bin/oxlint')
            ? '/opt/civikitchen-oxlint' : $this->checkoutRoot . '/toolbelt/oxlint';
        $oxlint = $toolchain . '/node_modules/.bin/oxlint';
        if (!is_executable($oxlint)) {
            return $this->error('no oxlint toolchain at /opt/civikitchen-oxlint - is this a civikitchen image?');
        }
        $catalog = Api4CatalogExport::locate($this->checkoutRoot);
        if ($catalog === null) {
            return $this->error('no Api4Catalog at /opt/civikitchen-phpstan-ext - is this a civikitchen image?');
        }
        $command = [$oxlint];
        if ($fix) {
            $command[] = '--fix';
        }
        if ($format !== '') {
            $command[] = "--format={$format}";
        }
        if ($core) {
            if ($fix || count($paths) > 1) {
                return $this->error('--core takes at most one argument, the core directory, and no --fix.');
            }
            return $this->runCore($toolchain, $command, $catalog, $paths[0] ?? '');
        }
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        $sourceFiles = $repository->source(['js', 'mjs', 'cjs', 'ts', 'tsx']);
        if ($paths === []) {
            if ($sourceFiles === []) {
                echo "ckeslint: no JavaScript or TypeScript in this repo - nothing to lint.\n";
                return 0;
            }
            echo 'ckeslint: ', count($sourceFiles), " JS/TS file(s) to lint.\n";
            $paths[] = '.';
        }
        if (is_file('.oxlintrc.json')) {
            echo "ckeslint: using this repo's own .oxlintrc.json (the CiviKitchen baseline does not apply; any jsPlugins it names must be installed in this repo's node_modules).\n";
        } elseif (glob('eslint.config.*')) {
            return $this->error('this repo ships an eslint.config.* but the image gate is oxlint now - add an .oxlintrc.json or remove the custom config.');
        } else {
            $command = [...$command, '-c', $toolchain . (is_file('tsconfig.json') ? '/.oxlintrc.json' : '/.oxlintrc-no-type-aware.json')];
            foreach (self::IGNORED_PATTERNS as $pattern) {
                $command = [...$command, '--ignore-pattern', $pattern];
            }
        }

        $extensionDirs = ['.', ...(glob('.civikitchen-siblings/*', GLOB_ONLYDIR) ?: [])];
        $catalogJson = $this->writeCatalog($catalog, $extensionDirs);
        $environment = $this->environment($toolchain, $catalogJson);
        $overlay = '';
        $nodeTypes = $toolchain . '/node_modules/@types/node';
        if (is_file('tsconfig.json') && !file_exists('node_modules/@types/node') && is_dir($nodeTypes)) {
            if ((is_dir('node_modules/@types') || @mkdir('node_modules/@types', 0777, true)) && @symlink($nodeTypes, 'node_modules/@types/node')) {
                $overlay = (string) getcwd() . '/node_modules/@types/node';
            }
            $config = json_decode((string) file_get_contents('tsconfig.json'), true);
            $hasTypes = is_array($config) && isset($config['compilerOptions']['types']);
            if ($overlay !== '' && !$hasTypes && $this->usesNodeGlobals($sourceFiles)) {
                echo "ckeslint: this repo uses Node globals but its tsconfig.json has no \"types\" - add \"types\": [\"node\"], or the type-aware rules see an untyped `process`.\n";
            }
        }
        try {
            return $this->runner->passthrough([...$command, ...$paths], $environment);
        } finally {
            @unlink($catalogJson);
            if ($overlay !== '') {
                @unlink($overlay);
                @rmdir(dirname($overlay));
                @rmdir(dirname($overlay, 2));
            }
        }
    }

    /**
     * Type-check and lint core's own JavaScript: a copy of it beside a
     * tsconfig (allowJs + checkJs) and a declaration file for its globals.
     *
     * @param non-empty-list<string> $command
     */
    private function runCore(string $toolchain, array $command, string $catalog, string $coreDir): int
    {
        $coreDir = $coreDir !== '' ? $coreDir : ((string) getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core');
        $coreDir = rtrim($coreDir, '/');
        if (!is_file($coreDir . '/Civi.php')) {
            return $this->error("not a CiviCRM core directory: {$coreDir} has no Civi.php");
        }
        $files = $this->coreJavaScript($coreDir);
        if ($files === []) {
            return $this->error("no JavaScript found under {$coreDir}");
        }
        $work = sys_get_temp_dir() . '/ckeslint-core-' . bin2hex(random_bytes(6));
        $catalogJson = $this->writeCatalog($catalog, []);
        try {
            foreach ($files as $file) {
                $target = $work . '/' . $file;
                if ((!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) || !copy($coreDir . '/' . $file, $target)) {
                    return $this->error("could not copy {$file} to {$work}");
                }
            }
            foreach (['tsconfig.json', 'civicrm-core.d.ts'] as $name) {
                if (!copy($toolchain . '/core/' . $name, $work . '/' . $name)) {
                    return $this->error("could not copy {$toolchain}/core/{$name}");
                }
            }
            $api = json_decode((string) file_get_contents($catalogJson), true);
            echo 'ckeslint --core: ', count($files), " JS file(s) from {$coreDir}, APIv4 catalog of CiviCRM ", $api['version'], ".\n";
            return $this->runner->passthrough(
                [...$command, '--type-aware', '--type-check', '-c', $toolchain . '/.oxlintrc-core.json', '.'],
                $this->environment($toolchain, $catalogJson),
                $work,
            );
        } finally {
            @unlink($catalogJson);
            $this->removeTree($work);
        }
    }

    /** @return list<string> core-relative paths of core's first-party .js */
    private function coreJavaScript(string $coreDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $entry): bool => $entry->isDir()
                ? !in_array($entry->getFilename(), self::CORE_SKIPPED_DIRECTORIES, true)
                : preg_match('/(?<!\.min|\.bundle)\.js$/', $entry->getFilename()) === 1,
        ));
        foreach ($iterator as $entry) {
            $files[] = substr($entry->getPathname(), strlen($coreDir) + 1);
        }
        sort($files);
        return $files;
    }

    /** @param list<string> $extensionDirs */
    private function writeCatalog(string $catalog, array $extensionDirs): string
    {
        $file = sys_get_temp_dir() . '/ckeslint-api4-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, json_encode(Api4CatalogExport::build($catalog, $extensionDirs), JSON_THROW_ON_ERROR));
        return $file;
    }

    /** @return array<string, string> */
    private function environment(string $toolchain, string $catalogJson): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['CIVIKITCHEN_API4_CATALOG'] = $catalogJson;
        if (($environment['OXLINT_TSGOLINT_PATH'] ?? '') === '') {
            foreach (glob($toolchain . '/node_modules/@oxlint-tsgolint/*/tsgolint') ?: [] as $candidate) {
                if (is_executable($candidate)) {
                    $environment['OXLINT_TSGOLINT_PATH'] = $candidate;
                    break;
                }
            }
        }
        return $environment;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    /** @param list<string> $files */
    private function usesNodeGlobals(array $files): bool
    {
        foreach ($files as $file) {
            $source = @file_get_contents($file);
            if (is_string($source) && preg_match('/process\.|__dirname|__filename/', $source) === 1) {
                return true;
            }
        }
        return false;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckeslint: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckeslint - JS/TS lint gate for CiviCRM extensions (CiviKitchen baseline, oxlint).

  ckeslint              lint project JS/TS
  ckeslint --fix        apply available fixes
  ckeslint path ...     lint given files/directories
  ckeslint --format=X   select oxlint reporter
  ckeslint --core [dir] type-check and lint CiviCRM core's own JavaScript
                        (dir defaults to $CIVICRM_CORE_DIR, then /var/www/html/core)
TXT;
    }
}
