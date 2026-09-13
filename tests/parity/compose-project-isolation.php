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

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: compose-project-isolation.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    foreach (ck_jobs_parsed($file) as $job => $definition) {
        $text = ck_job_text($definition);

        // Only jobs that actually bring a stack up can collide. `exec` alone
        // reaches into whatever the booting job created and is not a boot.
        if (preg_match('/docker compose\\b[^\\n]*\\bup\\b/', $text) !== 1) {
            continue;
        }
        // A hardcoded GitHub-hosted label means one VM per job — no shared
        // Docker daemon, nothing to collide over. Read from the `runs-on` key,
        // not from the job text: a label named in a comment is not a runner.
        $runsOn = ck_scalar_text($definition['runs-on'] ?? null);
        if ($runsOn !== '' && !str_contains($runsOn, '${{')) {
            continue;
        }
        if (str_contains($text, 'COMPOSE_PROJECT_NAME')) {
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
