<?php

declare(strict_types=1);

/**
 * Every job of the shared extension CI workflow that boots a compose stack has
 * to layer the sibling override onto its `up`, and reach the private-dependency
 * steps that produce it. An extension whose `<requires>` names a sibling the
 * extension registry does not know cannot boot at all without them, so a job
 * that skips the wiring is not "unsupported" — it is unusable for that repo.
 *
 * Usage: php sibling-wiring.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: sibling-wiring.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    $yaml = (string) file_get_contents($file);

    foreach (ck_jobs($yaml) as $job => $range) {
        $body = ck_job_body($yaml, $range['start'], $range['end']);

        // Only a job that brings a stack UP installs the extension; one that
        // only `exec`s reaches into a stack another job booted.
        if (preg_match('/docker compose\b[^\n]*\bup\b/', $body) !== 1) {
            continue;
        }
        if (!str_contains($body, 'private-deps')) {
            $problems[] = "$file: job '$job' boots a stack without the private-dependency steps";
        }
        if (!str_contains($body, 'CK_SIBLING_OVERRIDE')) {
            $problems[] = "$file: job '$job' boots a stack without layering CK_SIBLING_OVERRIDE";
        }
    }
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "$problem\n");
    }
    exit(1);
}

exit(0);
