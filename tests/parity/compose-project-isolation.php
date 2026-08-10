<?php

declare(strict_types=1);

/**
 * A workflow job that boots a compose stack AND can land on a self-hosted
 * runner must name its own compose project. The extension repos' compose files
 * name the project after the extension, so two such jobs of one run share it —
 * and the second one's `down -v --remove-orphans` SIGKILLs the first one's
 * containers (exit 137).
 *
 * A job pinned to a GitHub-hosted label gets its own VM and cannot collide, so
 * it is not flagged; the trigger is `runs-on` carrying an expression, which is
 * how a job reaches the self-hosted host with its several job slots.
 *
 * Usage: php compose-project-isolation.php <workflow.yml> [<workflow.yml> ...]
 */

/**
 * Job blocks keyed by name, each as its raw line range.
 *
 * @return array<string, array{start: int, end: int}>
 */
function ck_jobs(string $yaml): array
{
    $lines = explode("\n", $yaml);
    $jobsAt = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^jobs:\s*$/', $line) === 1) {
            $jobsAt = $i;
            break;
        }
    }
    if ($jobsAt === null) {
        return [];
    }

    $jobs = [];
    $current = null;
    for ($i = $jobsAt + 1, $n = count($lines); $i < $n; $i++) {
        // A job header is exactly one indent level below `jobs:`.
        if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $lines[$i], $m) === 1) {
            if ($current !== null) {
                $jobs[$current]['end'] = $i - 1;
            }
            $current = $m[1];
            $jobs[$current] = ['start' => $i, 'end' => $n - 1];
        }
    }

    return $jobs;
}

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: compose-project-isolation.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    $yaml = (string) file_get_contents($file);
    $lines = explode("\n", $yaml);

    foreach (ck_jobs($yaml) as $job => $range) {
        $body = implode("\n", array_slice($lines, $range['start'], $range['end'] - $range['start'] + 1));
        // A compose invocation routinely wraps over a backslash continuation,
        // so `up` lands on a later line than `docker compose`. Join them first
        // or the boot goes unseen and the job is silently waved through.
        $joined = preg_replace('/\\\\\n\s*/', ' ', $body) ?? $body;

        // Only jobs that actually bring a stack up can collide. `exec` alone
        // reaches into whatever the booting job created and is not a boot.
        if (preg_match('/docker compose\b[^\n]*\bup\b/', $joined) !== 1) {
            continue;
        }
        // A hardcoded GitHub-hosted label means one VM per job — no shared
        // Docker daemon, nothing to collide over.
        if (preg_match('/^\s*runs-on:\s*(.+)$/m', $joined, $m) === 1
            && !str_contains($m[1], '${{')
        ) {
            continue;
        }
        if (str_contains($joined, 'COMPOSE_PROJECT_NAME')) {
            continue;
        }
        $problems[] = "$file: job '$job' boots a compose stack without COMPOSE_PROJECT_NAME";
    }
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "$problem\n");
    }
    exit(1);
}

exit(0);
