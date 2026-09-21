<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Process;

class Runner
{
    /**
     * The message for a program that cannot be spawned, or null when it can.
     * Checked before proc_open, whose own failure is a raw PHP warning that
     * reads like a memory or seccomp problem; a login shell resetting PATH is
     * the usual cause. No fallback path: a guessed tool is worse than an error.
     * Searched on this process's PATH: proc_open (posix_spawnp/execvpe) ignores
     * the PATH of the environment passed to the child.
     *
     * @param non-empty-list<string> $command
     */
    private function unresolved(array $command): ?string
    {
        $program = $command[0];
        if (str_contains($program, '/')) {
            return is_file($program) && is_executable($program)
                ? null : "ck: {$program} is not an executable file\n";
        }
        $path = (string) getenv('PATH');
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory !== '' && is_file($directory . '/' . $program) && is_executable($directory . '/' . $program)) {
                return null;
            }
        }
        return "ck: {$program} was not found on PATH ({$path})\n";
    }

    /** @param non-empty-list<string> $command */
    /** @param array<string, string>|null $environment */
    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $missing = $this->unresolved($command);
        if ($missing !== null) {
            fwrite(STDERR, $missing);
            return 2;
        }
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            fwrite(STDERR, 'ck: could not start ' . $command[0] . "\n");
            return 2;
        }
        return proc_close($process);
    }

    /** @param non-empty-list<string> $command @return array{status: int, output: string} */
    /** @param array<string, string>|null $environment */
    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $missing = $this->unresolved($command);
        if ($missing !== null) {
            return ['status' => 2, 'output' => $missing];
        }
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            return ['status' => 2, 'output' => 'ck: could not start ' . $command[0] . "\n"];
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['status' => proc_close($process), 'output' => $output === false ? '' : $output];
    }

    /** @param non-empty-list<string> $command @return array{status:int,stdout:string,stderr:string} */
    /** @param array<string, string>|null $environment */
    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $missing = $this->unresolved($command);
        if ($missing !== null) {
            return ['status' => 2, 'stdout' => '', 'stderr' => $missing];
        }
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            return ['status' => 2, 'stdout' => '', 'stderr' => 'ck: could not start ' . $command[0] . "\n"];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdoutStream = $pipes[1];
        $stderrStream = $pipes[2];
        $stdout = '';
        $stderr = '';
        while ($pipes !== []) {
            $read = array_values($pipes);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false) {
                    if ($stream === $stdoutStream) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
                if (feof($stream)) {
                    $index = $stream === $stdoutStream ? 1 : 2;
                    fclose($stream);
                    unset($pipes[$index]);
                }
            }
        }
        foreach ($pipes as $stream) {
            fclose($stream);
        }
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param non-empty-list<string> $command @param array<string, string>|null $environment */
    public function redirect(array $command, string $outputFile, ?array $environment = null): int
    {
        $missing = $this->unresolved($command);
        if ($missing !== null) {
            fwrite(STDERR, $missing);
            return 2;
        }
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
