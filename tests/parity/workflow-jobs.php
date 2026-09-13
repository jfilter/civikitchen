<?php

declare(strict_types=1);

/**
 * Job blocks of a workflow file, keyed by name, each as its raw line range.
 *
 * Shared by the workflow-structure checkers in this directory.
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

/**
 * The job's body with backslash line continuations joined.
 *
 * A compose invocation routinely wraps, so `up` lands on a later line than
 * `docker compose`. Join them first or the boot goes unseen.
 */
function ck_job_body(string $yaml, int $start, int $end): string
{
    $lines = explode("\n", $yaml);
    $body = implode("\n", array_slice($lines, $start, $end - $start + 1));

    return preg_replace('/\\\\\n\s*/', ' ', $body) ?? $body;
}

/**
 * The workflow's `jobs:` mapping, parsed as YAML.
 *
 * Comments are gone by construction, so a check built on this cannot be
 * talked into passing by a comment that mentions what the job does not do.
 *
 * @return array<string, array<mixed>>
 */
function ck_jobs_parsed(string $file): array
{
    require_once dirname(__DIR__, 2) . '/packages/civikitchen-scenario-schema/vendor/autoload.php';

    $document = Symfony\Component\Yaml\Yaml::parseFile($file);
    if (!is_array($document) || !is_array($document['jobs'] ?? null)) {
        return [];
    }

    $jobs = [];
    foreach ($document['jobs'] as $name => $job) {
        if (is_array($job)) {
            $jobs[(string) $name] = $job;
        }
    }

    return $jobs;
}

/**
 * Every scalar of a parsed job, one per line, backslash continuations joined.
 *
 * A compose invocation routinely wraps, so `up` lands on a later line than
 * `docker compose`. Join them first or the boot goes unseen.
 */
function ck_job_text(array $job): string
{
    $scalars = [];
    array_walk_recursive($job, static function ($value) use (&$scalars): void {
        if (is_scalar($value)) {
            $scalars[] = (string) $value;
        }
    });
    $text = implode("\n", $scalars);

    return preg_replace('/\\\\\n\s*/', ' ', $text) ?? $text;
}

/**
 * The `uses:` values of the job's steps, in order.
 *
 * @return list<string>
 */
function ck_job_uses(array $job): array
{
    $uses = [];
    foreach ($job['steps'] ?? [] as $step) {
        if (is_array($step) && is_string($step['uses'] ?? null)) {
            $uses[] = $step['uses'];
        }
    }

    return $uses;
}
