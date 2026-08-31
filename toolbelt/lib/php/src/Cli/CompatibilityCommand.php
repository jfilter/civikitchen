<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CompatibilityCommand implements Command
{
    public function __construct(private readonly Runner $runner = new Runner())
    {
    }

    public function run(array $arguments): int
    {
        $testVersion = '';
        $paths = [];
        $literal = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!$literal && $argument === '--') {
                $literal = true;
                continue;
            }
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && in_array($argument, ['--php', '--range'], true)) {
                if ($arguments === []) {
                    return $this->error("{$argument} needs a value");
                }
                $value = (string) array_shift($arguments);
                $testVersion = $argument === '--php' ? "{$value}-" : $value;
                continue;
            }
            if (!$literal && str_starts_with($argument, '--php=')) {
                $testVersion = substr($argument, 6) . '-';
            } elseif (!$literal && str_starts_with($argument, '--range=')) {
                $testVersion = substr($argument, 8);
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        if ($testVersion === '') {
            $composer = is_file('composer.json') ? json_decode((string) file_get_contents('composer.json'), true) : null;
            $requirement = is_array($composer) ? ($composer['require']['php'] ?? '') : '';
            if (!is_string($requirement) || preg_match('/(\d+)\.(\d+)/', $requirement, $matches) !== 1) {
                return $this->error('no PHP floor in composer.json require.php - declare one, or pass --php');
            }
            $testVersion = $matches[0] . '-';
        }
        if ($paths === []) {
            foreach (['Civi', 'CRM', 'api', 'managed'] as $directory) {
                if (is_dir($directory)) {
                    $paths[] = $directory;
                }
            }
            foreach (glob('*.php') ?: [] as $file) {
                if (is_file($file)) {
                    $paths[] = $file;
                }
            }
            if ($paths === []) {
                $paths[] = '.';
            }
        }
        echo "ckcompat: PHP {$testVersion} (from composer.json unless overridden)\n";
        $failed = false;
        $floor = explode('-', $testVersion, 2)[0];
        $mago = version_compare($floor, '8.0', '>=') ? $this->findExecutable('mago') : null;
        if ($mago === null) {
            fwrite(STDERR, "ckcompat: no usable mago for PHP {$floor} - floor parse check skipped\n");
        } else {
            $files = $this->phpFiles($paths);
            if ($files === []) {
                echo "ckcompat: no PHP files for the floor parse check.\n";
            } elseif ($this->runner->passthrough([$mago, '--php-version', $floor, 'lint', '--semantics', ...$files]) !== 0) {
                $failed = true;
            }
        }
        $status = $this->runner->passthrough([
            'phpcs', '-q', '--standard=PHPCompatibility', '--runtime-set', 'testVersion', $testVersion,
            '--extensions=php', '--ignore=*/vendor/*,*/node_modules/*,*.civix.php,*/CRM/*/DAO/*,*/.civikitchen-siblings/*',
            ...$paths,
        ]);
        return $status !== 0 || $failed ? 1 : 0;
    }

    /** @param list<string> $paths @return list<string> */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = $file->getPathname();
                if ($file->isFile() && str_ends_with($name, '.php')
                    && preg_match('#(^|/)(vendor|node_modules|\.civikitchen-siblings)/|\.civix\.php$|(^|/)CRM/[^/]+/DAO/#', $name) !== 1) {
                    $files[] = $name;
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function findExecutable(string $name): ?string
    {
        $result = $this->runner->capture(['sh', '-c', 'command -v "$1"', 'sh', $name]);
        $path = trim($result['output']);
        return $result['status'] === 0 && $path !== '' ? $path : null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckcompat: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckcompat - check PHP cross-version compatibility against the declared floor.

  ckcompat                 check composer.json require.php floor
  ckcompat path ...        check the given files/directories
  ckcompat --php 8.1       check against this floor
  ckcompat --range 8.1-8.4 check a closed range
TXT;
    }
}
