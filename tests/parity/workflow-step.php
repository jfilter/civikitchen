<?php

declare(strict_types=1);

// Prints the `run:` script of one named step, so a test can execute it as written;
// a fifth argument names another key (`if`), and `env` prints the mapping as NAME<TAB>value lines.
require __DIR__ . '/workflow-jobs.php';

[, $file, $job, $name, $part] = $argv + [null, '', '', '', 'run'];
foreach (ck_jobs_parsed($file)[$job]['steps'] ?? [] as $step) {
    if (is_array($step) && ($step['name'] ?? null) === $name && is_string($step['run'] ?? null)) {
        if ($part === 'env') {
            foreach ($step['env'] ?? [] as $variable => $value) {
                echo $variable, "\t", ck_scalar_text($value), "\n";
            }
            exit(0);
        }
        echo ck_scalar_text($step[$part] ?? '');
        exit(0);
    }
}
fwrite(STDERR, "no run step '{$name}' in job {$job} of {$file}\n");
exit(1);
