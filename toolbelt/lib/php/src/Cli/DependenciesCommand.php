<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;

final class DependenciesCommand implements Command
{
    public function __construct(private readonly Runner $runner)
    {
    }

    public function run(array $arguments): int
    {
        $usage = <<<'TXT'
ck dependencies — check composer.json against the dependencies the code really uses.

  ck dependencies                 analyse this extension
  ck dependencies --config FILE   use another config

Run from the extension root. A repo shipping composer-dependency-analyser.php
gets that config instead of the bundled one.
TXT;
        $toolArguments = [];
        $customConfig = false;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $usage, "\n";
                return 0;
            }
            if ($argument === '--config') {
                $value = $arguments[++$index] ?? '';
                if ($value === '') {
                    fwrite(STDERR, "ckdeps: --config needs a file\n");
                    return 2;
                }
                $customConfig = true;
                array_push($toolArguments, '--config', $value);
                continue;
            }
            $toolArguments[] = $argument;
        }
        if (!is_file('composer.json')) {
            fwrite(STDERR, "ckdeps: no composer.json here — run from the extension root\n");
            return 2;
        }
        if (!$customConfig && !is_file('composer-dependency-analyser.php')) {
            array_push($toolArguments, '--config', '/opt/civikitchen-composer-deps.php');
        }
        $result = $this->runner->capture(['composer-dependency-analyser', ...$toolArguments]);
        echo $result['output'];
        if ($result['output'] !== '' && !str_ends_with($result['output'], "\n")) {
            echo "\n";
        }
        if ($result['status'] !== 0 && str_contains($result['output'], 'No dependencies found in')) {
            echo "ckdeps: nothing declared to analyse (extension depends on CiviCRM core only).\n";
            return 0;
        }
        return $result['status'];
    }
}
