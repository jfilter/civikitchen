<?php

declare(strict_types=1);

/**
 * policy.civicrm only reaches a stack when every image a job hands to compose
 * comes from the resolved value. A job env reading inputs.image directly
 * skips the policy; only the resolver's own CK_IMAGE_INPUT may read it.
 *
 * Usage: php image-wiring.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: image-wiring.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    foreach (ck_jobs_parsed($file) as $job => $definition) {
        $envs = [$definition['env'] ?? []];
        foreach ((array) ($definition['steps'] ?? []) as $step) {
            if (is_array($step)) {
                $envs[] = $step['env'] ?? [];
            }
        }
        foreach ($envs as $env) {
            foreach ((array) $env as $name => $value) {
                if ($name !== 'CK_IMAGE_INPUT' && ck_expression_reads(ck_scalar_text($value), 'inputs.image')) {
                    $problems[] = "$file: job '$job' sets $name from inputs.image, bypassing policy.civicrm";
                }
            }
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDERR, "$problem\n");
}
exit($problems === [] ? 0 : 1);
