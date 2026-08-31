<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;

final class LintCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $fix = false;
        $all = false;
        $paths = [];
        $literal = false;
        foreach ($arguments as $argument) {
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && $argument === '--') {
                $literal = true;
            } elseif (!$literal && $argument === '--fix') {
                $fix = true;
            } elseif (!$literal && $argument === '--all') {
                $all = true;
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if ($paths === [] && !$all) {
            $paths = $repository->changedPhp();
            if ($paths === []) {
                echo "cklint: no changed PHP files to lint.\n";
                return 0;
            }
        }
        $ignore = ['*/.civikitchen-siblings/*'];
        foreach ($repository->vendoredPrefixes() as $prefix) {
            $ignore[] = "*/{$prefix}/*";
            $ignore[] = "{$prefix}/*";
        }
        $phpcs = [$fix ? 'phpcbf' : 'phpcs', '-q', '--ignore=' . implode(',', $ignore), '--runtime-set', 'ignore_warnings_on_exit', '1'];
        $hasProjectConfig = is_file('phpcs.xml') || is_file('phpcs.xml.dist');
        if (!$hasProjectConfig) {
            $phpcs = [...$phpcs, '--standard=CiviKitchen', '--extensions=php'];
        }
        if (!$all) {
            foreach ($paths as $path) {
                if (is_file($path) && str_ends_with($path, '.php')
                    && $this->runner->passthrough([PHP_BINARY, '-l', $path]) !== 0) {
                    return 1;
                }
            }
        }
        if ($paths !== []) {
            $phpcs = [...$phpcs, ...$paths];
        } elseif (!$hasProjectConfig) {
            $phpcs[] = '.';
        }
        $failed = $this->runner->passthrough($phpcs) !== 0;

        $mago = $this->findExecutable('mago');
        if ($mago === null) {
            return $this->error('no mago on PATH - is this a civikitchen image?');
        }
        if (!$repository->isGitCheckout()) {
            fwrite(STDERR, "cklint: not a git checkout - skipping the mago lint stage (its file list comes from git).\n");
            return $failed ? 1 : 0;
        }
        $scope = $all ? [] : $paths;
        $files = $repository->source(['php'], $scope, true, true);
        $files = array_values(array_filter($files, static fn(string $file): bool => $file !== 'tests/phpunit/bootstrap.php'));
        if ($files === []) {
            echo "cklint: no PHP files for the mago lint stage.\n";
        } else {
            $magoArguments = [$mago];
            if (is_file('mago.toml')) {
                echo "cklint: using this repo's own mago.toml (the CiviKitchen lint baseline does not apply).\n";
            } else {
                $config = is_file('/opt/civikitchen-mago/mago.toml')
                    ? '/opt/civikitchen-mago/mago.toml' : $this->checkoutRoot . '/toolbelt/mago/mago.toml';
                $magoArguments = [...$magoArguments, '--config', $config];
            }
            $magoArguments[] = 'lint';
            if ($fix) {
                $magoArguments = [...$magoArguments, '--fix', '--format-after-fix'];
            }
            if ($this->runner->passthrough([...$magoArguments, ...$files]) !== 0) {
                $failed = true;
            }
        }
        return $failed ? 1 : 0;
    }

    private function findExecutable(string $name): ?string
    {
        $result = $this->runner->capture(['sh', '-c', 'command -v "$1"', 'sh', $name]);
        $path = trim($result['output']);
        return $result['status'] === 0 && $path !== '' ? $path : null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "cklint: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
cklint - PHP linting for CiviCRM extensions (phpcs + mago lint).

  cklint                  lint PHP files with uncommitted git changes
  cklint --all            lint the whole project
  cklint path ...         lint given files/directories
  cklint --fix [path...]  auto-fix findings
TXT;
    }
}
