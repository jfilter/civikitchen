<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;

final class MutationCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $floor = $this->policyValue('mutation_min_msi');
        if ($floor === '') {
            echo "ckmutate: no policy.mutation.minimum_msi in civikitchen.yaml - nothing to enforce, skipping.\n";
            echo "ckmutate: to adopt this, set policy.mutation.minimum_msi to the measured score and ratchet it.\n";
            return 0;
        }
        $infection = $this->findExecutable('infection');
        if ($infection === null) {
            return $this->error('infection is not installed in this image - a scheduled job on an older image tag should pin a newer one.');
        }
        if (!is_file('phpunit.xml') && !is_file('phpunit.xml.dist')) {
            return $this->error('no phpunit config - mutation testing needs a suite to run against.');
        }
        $extensions = array_map('strtolower', get_loaded_extensions());
        if (!in_array('pcov', $extensions, true) && !in_array('xdebug', $extensions, true)) {
            return $this->error('no pcov/xdebug loaded - infection cannot collect coverage.');
        }
        $coveredFloor = $this->policyValue('mutation_min_covered_msi');
        $pathValue = $this->policyValue('mutation_paths');
        $directories = $pathValue !== '' ? array_values(array_filter(explode(',', $pathValue), 'strlen')) : [];
        if ($directories === []) {
            foreach (['Civi', 'CRM', 'src'] as $directory) {
                if (is_dir($directory)) {
                    $directories[] = $directory;
                }
            }
        }
        if ($directories === []) {
            return $this->error('no source directory to mutate (looked for Civi/, CRM/, src/ - or set policy.mutation.paths).');
        }
        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                return $this->error("mutation_paths names '{$directory}', which does not exist here.");
            }
        }
        $config = '.ckmutate.json';
        if (file_exists($config)) {
            return $this->error("{$config} already exists - refusing to overwrite it.");
        }
        $temporary = sys_get_temp_dir() . '/ckmutate-run-' . bin2hex(random_bytes(6));
        if (!mkdir($temporary, 0700)) {
            return $this->error('could not create temporary directory.');
        }
        register_shutdown_function(static function () use ($config, $temporary): void {
            @unlink($config);
            @rmdir($temporary);
        });
        $phpunit = $this->findExecutable('phpunit') ?? 'phpunit';
        $configuration = [
            '$schema' => 'vendor/infection/infection/resources/schema.json',
            'source' => ['directories' => $directories],
            'tmpDir' => $temporary,
            'logs' => ['text' => 'php://stdout'],
            'phpUnit' => ['customPath' => $phpunit],
            'testFramework' => 'phpunit',
        ];
        $json = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($config, $json . "\n") === false) {
            return $this->error("cannot write {$config}");
        }
        $command = [$infection, "--configuration={$config}", '--no-progress', '--no-interaction', "--min-msi={$floor}"];
        if ($coveredFloor !== '') {
            $command[] = "--min-covered-msi={$coveredFloor}";
        }
        if ($pathValue !== '') {
            echo 'ckmutate: mutating ', implode(' ', $directories), " (mutation_paths), floor {$floor}% MSI.\n";
        } else {
            $base = (string) (getenv('CK_MUTATE_BASE') ?: 'origin/main');
            if ($this->runner->capture(['git', 'rev-parse', '--verify', '--quiet', $base])['status'] !== 0) {
                return $this->error("CK_MUTATE_BASE '{$base}' is not a resolvable ref - a shallow checkout needs fetch-depth: 0, or set mutation_paths.");
            }
            echo "ckmutate: mutating the lines changed against {$base}, floor {$floor}% MSI.\n";
            $command = [...$command, '--git-diff-lines', "--git-diff-base={$base}"];
        }
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['CIVICRM_UF'] = $environment['CIVICRM_UF'] ?? 'UnitTests';
        $status = $this->runner->passthrough([...$command, ...$arguments], $environment);
        if ($status === 0) {
            echo "ckmutate: floor met.\n";
        } else {
            fwrite(STDERR, "ckmutate: FAIL - the score is below policy.mutation.minimum_msi, or the run broke (exit {$status}).\nckmutate: a surviving mutant is a line the suite EXECUTES but does not assert on - add the assertion, do not lower the floor.\n");
        }
        return $status;
    }

    private function policyValue(string $key): string
    {
        $binary = is_executable($this->checkoutRoot . '/toolbelt/bin/ckconform')
            ? $this->checkoutRoot . '/toolbelt/bin/ckconform' : 'ckconform';
        $result = $this->runner->capture([$binary, '--policy', $key]);
        return $result['status'] === 0 ? trim(strtok($result['output'], "\n") ?: '') : '';
    }

    private function findExecutable(string $name): ?string
    {
        $result = $this->runner->capture(['sh', '-c', 'command -v "$1"', 'sh', $name]);
        $path = trim($result['output']);
        return $result['status'] === 0 && $path !== '' ? $path : null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckmutate: {$message}\n");
        return 2;
    }
}
