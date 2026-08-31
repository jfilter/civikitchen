<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;

final class JavaScriptLintCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $fix = false;
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
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $toolchain = is_executable('/opt/civikitchen-oxlint/node_modules/.bin/oxlint')
            ? '/opt/civikitchen-oxlint' : $this->checkoutRoot . '/toolbelt/oxlint';
        $oxlint = $toolchain . '/node_modules/.bin/oxlint';
        if (!is_executable($oxlint)) {
            return $this->error('no oxlint toolchain at /opt/civikitchen-oxlint - is this a civikitchen image?');
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
        $command = [$oxlint];
        if ($fix) {
            $command[] = '--fix';
        }
        if ($format !== '') {
            $command[] = "--format={$format}";
        }
        if (is_file('.oxlintrc.json')) {
            echo "ckeslint: using this repo's own .oxlintrc.json (the CiviKitchen baseline does not apply; any jsPlugins it names must be installed in this repo's node_modules).\n";
        } elseif (glob('eslint.config.*')) {
            return $this->error('this repo ships an eslint.config.* but the image gate is oxlint now - add an .oxlintrc.json or remove the custom config.');
        } else {
            $command = [...$command, '-c', $toolchain . (is_file('tsconfig.json') ? '/.oxlintrc.json' : '/.oxlintrc-no-type-aware.json')];
            foreach (['**/node_modules/**', '**/vendor/**', '**/dist/**', '**/build/**', '**/.civikitchen-siblings/**',
                '**/bower_components/**', '**/packages/**', '**/*.min.js', '**/*.bundle.js'] as $pattern) {
                $command = [...$command, '--ignore-pattern', $pattern];
            }
        }

        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        if (($environment['OXLINT_TSGOLINT_PATH'] ?? '') === '') {
            foreach (glob($toolchain . '/node_modules/@oxlint-tsgolint/*/tsgolint') ?: [] as $candidate) {
                if (is_executable($candidate)) {
                    $environment['OXLINT_TSGOLINT_PATH'] = $candidate;
                    break;
                }
            }
        }
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
            if ($overlay !== '') {
                @unlink($overlay);
                @rmdir(dirname($overlay));
                @rmdir(dirname($overlay, 2));
            }
        }
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
TXT;
    }
}
