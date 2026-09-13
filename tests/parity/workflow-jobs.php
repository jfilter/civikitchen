<?php

declare(strict_types=1);

/**
 * Parsed-YAML access to a workflow's jobs, shared by the workflow-structure
 * checkers in this directory.
 */

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
    $text = ck_scalar_text($job);

    return preg_replace('/\\\\\n\s*/', ' ', $text) ?? $text;
}

/**
 * Every scalar of one parsed YAML value, one per line.
 *
 * A key like `runs-on` carries a string, a list of labels or a group mapping;
 * all three answer the same question and this flattens them to one text.
 */
function ck_scalar_text(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    if (!is_array($value)) {
        return '';
    }

    $scalars = [];
    array_walk_recursive($value, static function ($leaf) use (&$scalars): void {
        if (is_scalar($leaf)) {
            $scalars[] = (string) $leaf;
        }
    });

    return implode("\n", $scalars);
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
