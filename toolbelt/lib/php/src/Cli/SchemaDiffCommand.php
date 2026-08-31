<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use Throwable;

final class SchemaDiffCommand implements Command
{
    public function __construct(private readonly Runner $runner = new Runner())
    {
    }

    public function run(array $arguments): int
    {
        $operation = array_shift($arguments) ?? '';
        if (in_array($operation, ['-h', '--help'], true)) {
            echo $this->usage();
            return 0;
        }
        return match ($operation) {
            'tables' => $arguments === [] ? $this->printTables() : $this->usageError(),
            'dump' => count($arguments) === 2 ? $this->dump($arguments[0], $arguments[1]) : $this->usageError(),
            'diff' => count($arguments) === 2 ? $this->diff($arguments[0], $arguments[1]) : $this->usageError(),
            'normalize' => $arguments === [] ? $this->normalizeStream() : $this->usageError(),
            default => $this->usageError(),
        };
    }

    /** @return list<string> */
    private function tables(): array
    {
        if (!is_file('info.xml')) {
            throw new \RuntimeException('no info.xml here - run from the extension root.');
        }
        $tables = [];
        foreach (glob('schema/*.entityType.php') ?: [] as $file) {
            try {
                $entity = include $file;
            } catch (Throwable $throwable) {
                throw new \RuntimeException("cannot read {$file}: " . $throwable->getMessage(), 0, $throwable);
            }
            if (is_array($entity) && is_string($entity['table'] ?? null) && $entity['table'] !== '') {
                $tables[] = $entity['table'];
            }
        }
        if (is_dir('xml/schema')) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('xml/schema', FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'xml') {
                    continue;
                }
                libxml_use_internal_errors(true);
                $xml = simplexml_load_file($file->getPathname());
                if (!$xml instanceof SimpleXMLElement) {
                    continue;
                }
                if ($xml->getName() === 'table' && trim((string) $xml->name) !== '') {
                    $tables[] = trim((string) $xml->name);
                }
                foreach ($xml->table ?? [] as $table) {
                    if (trim((string) $table->name) !== '') {
                        $tables[] = trim((string) $table->name);
                    }
                }
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    private function printTables(): int
    {
        try {
            foreach ($this->tables() as $table) {
                echo $table, "\n";
            }
            return 0;
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    private function dump(string $database, string $output): int
    {
        try {
            $tables = $this->tables();
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }
        if ($tables === []) {
            echo "ckschemadiff: this extension declares no tables of its own - writing an empty dump.\n";
            return file_put_contents($output, '') === false ? $this->error("cannot write {$output}") : 0;
        }
        $environment = static fn(string $key, string $default): string => (string) (getenv($key) ?: $default);
        $command = [
            'mysqldump', '-h', $environment('CIVICRM_DB_HOST', 'db'), '-P', $environment('CIVICRM_DB_PORT', '3306'),
            '-u', $environment('CIVICRM_DB_USER', 'civicrm'), '-p' . $environment('CIVICRM_DB_PASSWORD', 'civicrm'),
            '--no-data', '--compact', '--skip-comments', '--skip-add-drop-table', '--skip-set-charset',
            $database, ...$tables,
        ];
        $result = $this->runner->captureSeparate($command);
        $stderr = preg_replace('/^.*Using a password on the command line.*\R?/m', '', $result['stderr']) ?? '';
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        if ($result['status'] !== 0) {
            return $result['status'];
        }
        $normalized = $this->normalize($result['stdout']);
        if ($normalized === '' || file_put_contents($output, $normalized) === false) {
            return $this->error("mysqldump produced nothing for {$database} (are the tables installed?)");
        }
        echo 'ckschemadiff: dumped ', count($tables), " table(s) from {$database} to {$output}\n";
        return 0;
    }

    private function normalizeStream(): int
    {
        $input = stream_get_contents(STDIN);
        echo $this->normalize($input === false ? '' : $input);
        return 0;
    }

    private function normalize(string $ddl): string
    {
        $lines = preg_split('/\R/', $ddl) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $line) ?? $line;
            if (str_starts_with($line, '/*!')) {
                continue;
            }
            $line = rtrim($line);
            if ($line !== '') {
                $result[] = $line;
            }
        }
        return $result === [] ? '' : implode("\n", $result) . "\n";
    }

    private function diff(string $left, string $right): int
    {
        foreach ([$left, $right] as $file) {
            if (!is_file($file)) {
                return $this->error("no such file: {$file}");
            }
        }
        $status = $this->runner->passthrough(['diff', '-u', '--label', 'fresh install of HEAD', $left, '--label', 'last release upgraded to HEAD', $right]);
        if ($status === 0) {
            echo "ckschemadiff: schemas match - install() and the upgrader agree.\n";
            return 0;
        }
        if ($status === 1) {
            fwrite(STDERR, "\nckschemadiff: the two schemas differ.\n\nA line only in fresh install of HEAD is missing from the upgrader; a line only\nin last release upgraded to HEAD is missing from install(). Fix whichever\ndescription is behind.\n");
        }
        return $status;
    }

    private function usageError(): int
    {
        fwrite(STDERR, $this->usage());
        return 2;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckschemadiff: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckschemadiff - install-vs-upgrade schema parity for a CiviCRM extension.

  ckschemadiff tables
  ckschemadiff dump DB OUTFILE
  ckschemadiff diff FILE_A FILE_B
  ckschemadiff normalize
TXT;
    }
}
