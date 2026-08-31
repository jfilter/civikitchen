<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use SimpleXMLElement;

final class CoverageCommand implements Command
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
        $config = is_file('phpunit.xml') ? 'phpunit.xml' : (is_file('phpunit.xml.dist') ? 'phpunit.xml.dist' : null);
        if ($config === null) {
            if ($this->policyValue('tests') === 'optional') {
                echo "ckcoverage: no phpunit config, and policy.tests declares it optional - nothing to measure.\n";
                return 0;
            }
            return $this->error('no phpunit config.');
        }
        $contents = file_get_contents($config);
        if ($contents === false || !str_contains($contents, '<coverage')) {
            return $this->error('phpunit config declares no <coverage> section - nothing to measure.');
        }

        $clover = tempnam(sys_get_temp_dir(), 'ckcoverage-');
        $runLog = tempnam(sys_get_temp_dir(), 'ckcoverage-run-');
        if ($clover === false || $runLog === false) {
            return $this->error('could not create temporary files.');
        }
        register_shutdown_function(static function () use ($clover, $runLog): void {
            @unlink($clover);
            @unlink($runLog);
        });

        echo "ckcoverage: running the suite with coverage (this is slower than a plain run) ...\n";
        $runner = $this->findExecutable('ckphpunit') ?? 'phpunit';
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['CIVICRM_UF'] = $environment['CIVICRM_UF'] ?? 'UnitTests';
        $status = $this->runner->redirect([$runner, '--coverage-clover', $clover, ...$arguments], $runLog, $environment);
        if ($status !== 0) {
            fwrite(STDERR, "ckcoverage: the test suite itself failed - coverage is meaningless until it passes.\n");
            $lines = file($runLog, FILE_IGNORE_NEW_LINES) ?: [];
            fwrite(STDERR, implode("\n", array_slice($lines, -25)) . "\n");
            return $status;
        }
        if (!is_file($clover) || filesize($clover) === 0) {
            return $this->error('phpunit produced no clover report (is pcov/xdebug loaded?)');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($clover);
        $metrics = $xml instanceof SimpleXMLElement ? $xml->project->metrics : null;
        if (!$metrics instanceof SimpleXMLElement) {
            return $this->error('no project metrics in clover');
        }
        $covered = (int) $metrics['coveredstatements'];
        $total = (int) $metrics['statements'];
        if ($total === 0) {
            return $this->error('zero executable statements measured - check the <coverage> include paths.');
        }
        $percentage = $covered * 100 / $total;
        $formatted = number_format($percentage, 2, '.', '');
        echo "ckcoverage: {$formatted}% line coverage ({$covered}/{$total} statements)\n";

        $floor = $this->policyValue('min_coverage');
        if ($floor === '') {
            echo "ckcoverage: no policy.coverage.minimum in civikitchen.yaml - reporting only.\n";
            return 0;
        }
        if (preg_match('/^\d+$/', $floor) !== 1) {
            return $this->error("policy.coverage.minimum is not a whole percentage: '{$floor}'");
        }
        if ($percentage >= (int) $floor) {
            echo "ckcoverage: floor {$floor}% met.\n";
            return 0;
        }
        fwrite(STDERR, "ckcoverage: FAIL - {$formatted}% is below policy.coverage.minimum ({$floor}%).\n");
        return 1;
    }

    private function policyValue(string $key): string
    {
        $local = $this->checkoutRoot . '/toolbelt/bin/ckconform';
        $binary = is_executable($local) ? $local : 'ckconform';
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
        fwrite(STDERR, "ckcoverage: {$message}\n");
        return 2;
    }
}
