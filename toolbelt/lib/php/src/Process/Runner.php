<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Process;

final class Runner
{
    /** @param non-empty-list<string> $command */
    /** @param array<string, string>|null $environment */
    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            fwrite(STDERR, 'ck: could not start ' . $command[0] . "\n");
            return 2;
        }
        return proc_close($process);
    }

    /** @param non-empty-list<string> $command @return array{status: int, output: string} */
    public function capture(array $command): array
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ], $pipes);
        if (!is_resource($process)) {
            return ['status' => 2, 'output' => 'ck: could not start ' . $command[0] . "\n"];
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['status' => proc_close($process), 'output' => $output === false ? '' : $output];
    }

    /** @param non-empty-list<string> $command @param array<string, string>|null $environment */
    public function redirect(array $command, string $outputFile, ?array $environment = null): int
    {
        $process = proc_open($command, [
            0 => STDIN,
            1 => ['file', $outputFile, 'w'],
            2 => ['redirect', 1],
        ], $pipes, null, $environment);
        if (!is_resource($process)) {
            fwrite(STDERR, 'ck: could not start ' . $command[0] . "\n");
            return 2;
        }
        return proc_close($process);
    }
}
