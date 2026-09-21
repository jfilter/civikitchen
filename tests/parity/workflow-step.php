<?php

declare(strict_types=1);

// Prints the `run:` script of one named step, so a test can execute it as written.
require __DIR__ . '/workflow-jobs.php';

[, $file, $job, $name] = $argv + [null, '', '', ''];
foreach (ck_jobs_parsed($file)[$job]['steps'] ?? [] as $step) {
    if (is_array($step) && ($step['name'] ?? null) === $name && is_string($step['run'] ?? null)) {
        echo $step['run'];
        exit(0);
    }
}
fwrite(STDERR, "no run step '{$name}' in job {$job} of {$file}\n");
exit(1);
