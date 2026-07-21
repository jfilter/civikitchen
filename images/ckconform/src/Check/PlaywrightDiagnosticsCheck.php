<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A Playwright suite that leaves nothing behind when it fails.
 *
 * A browser test that fails in CI is a stack trace and a screenshot of nothing.
 * Without a trace there is no way to see what the page actually looked like, and
 * re-running locally often will not reproduce it. Several separate gaps each
 * look configured while producing exactly that:
 *
 *   - `trace: 'on-first-retry'` records nothing for the FIRST failure. With
 *     retries disabled it never records at all, and where retries are on, a
 *     flaky test that passes on the second attempt leaves a trace nobody reads.
 *     `retain-on-failure` keeps one for every attempt that actually failed.
 *   - No reporter configured means no `playwright-report/` directory exists —
 *     and two workflows were archiving that exact path, so every upload was
 *     empty and no one noticed.
 *   - An `upload-artifact` step with no `if:` is SKIPPED when a previous step
 *     fails — which is exactly, and only, when the report matters. It needs
 *     `if: always()` (or `!cancelled()` / `failure()`).
 *   - The upload has to sit in the SAME job as the Playwright run: GitHub
 *     runners share no filesystem, so an upload in another job collects
 *     nothing. A global "some workflow runs playwright, some workflow uploads"
 *     lets two unrelated workflows satisfy each other.
 */
final class PlaywrightDiagnosticsCheck implements Check
{
    private const FAILURE_TOLERANT = ['always(', '!cancelled(', 'failure('];

    public function name(): string
    {
        return 'playwright-diagnostics';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $configs = array_values(array_filter(
            $context->trackedFiles(),
            static fn (string $f): bool
                => preg_match('/^playwright\.config\.(ts|js|mjs)$/', basename($f)) === 1
        ));
        if ($configs === []) {
            return;
        }

        $problems = [];
        foreach ($configs as $config) {
            $body = $context->read($config) ?? '';
            if (preg_match("/trace:\s*'retain-on-failure'/", $body) !== 1) {
                $problems[] = $config . ': no retain-on-failure trace';
            }
            if (preg_match('/reporter:/', $body) !== 1) {
                $problems[] = $config . ': no reporter, so no playwright-report/ is written';
            }
        }

        $problems = array_merge($problems, $this->ciProblems($context));

        if ($problems !== []) {
            $reporter->fail('playwright leaves nothing to debug with: ' . implode('; ', $problems));
        } else {
            $reporter->ok('playwright records a trace and CI keeps it');
        }
    }

    /**
     * @return list<string>
     */
    private function ciProblems(Context $context): array
    {
        $jobs = [];
        foreach ($context->workflows() as $workflow) {
            $body = $this->stripComments($context->read($workflow) ?? '');
            foreach ($this->jobs($body) as $name => $text) {
                $jobs[$workflow . ':' . $name] = $text;
            }
        }

        // Unusual indentation, or a workflow this splitter cannot read: fall back
        // to the old whole-estate question rather than inventing a problem.
        if ($jobs === []) {
            return $this->flatCiProblems($context);
        }

        $playwrightJobs = array_filter($jobs, fn (string $t): bool => $this->runsPlaywright($t));
        if ($playwrightJobs === []) {
            return [];
        }

        $problems = [];
        foreach ($playwrightJobs as $id => $text) {
            $upload = $this->reportUploadStep($text);
            if ($upload === null) {
                $problems[] = $id . ' runs playwright but does not upload its report in the same job';
            } elseif (!$this->runsOnFailure($upload)) {
                $problems[] = $id . ' uploads the report without if: always(), so a failed run skips it';
            }
        }

        return $problems;
    }

    /**
     * The pre-job fallback: playwright runs somewhere and a report is uploaded
     * somewhere. Weaker, but never worse than the check was before.
     *
     * @return list<string>
     */
    private function flatCiProblems(Context $context): array
    {
        $runs = false;
        $uploads = false;
        foreach ($context->workflows() as $workflow) {
            $body = $this->stripComments($context->read($workflow) ?? '');
            $runs = $runs || $this->runsPlaywright($body);
            $uploads = $uploads || ($this->mentionsUpload($body) && $this->mentionsReport($body));
        }

        return $runs && !$uploads ? ['CI runs playwright but no workflow uploads the report'] : [];
    }

    private function runsPlaywright(string $text): bool
    {
        return str_contains($text, 'playwright');
    }

    private function mentionsUpload(string $text): bool
    {
        return str_contains($text, 'upload-artifact');
    }

    private function mentionsReport(string $text): bool
    {
        return str_contains($text, 'playwright-report') || str_contains($text, 'test-results');
    }

    /**
     * The step block within a job that uploads the report, or null.
     */
    private function reportUploadStep(string $jobText): ?string
    {
        foreach ($this->steps($jobText) as $step) {
            if ($this->mentionsUpload($step) && $this->mentionsReport($step)) {
                return $step;
            }
        }

        return null;
    }

    private function runsOnFailure(string $step): bool
    {
        if (preg_match('/^\s*if:\s*(.+)$/m', $step, $match) !== 1) {
            return false;
        }
        foreach (self::FAILURE_TOLERANT as $token) {
            if (str_contains($match[1], $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a workflow body into its jobs, name => body text.
     *
     * Indentation-based: jobs are the two-space keys under `jobs:`, each running
     * until the next two-space key or a dedent to column zero. GitHub Actions
     * fixes this layout, so a plain reader is enough and avoids a YAML dependency
     * the image does not carry.
     *
     * @return array<string, string>
     */
    private function jobs(string $body): array
    {
        $jobs = [];
        $inJobs = false;
        $current = null;
        $buffer = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^jobs:\s*$/', $line) === 1) {
                $inJobs = true;
                continue;
            }
            if (!$inJobs) {
                continue;
            }
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
                if ($current !== null) {
                    $jobs[$current] = implode("\n", $buffer);
                }
                $current = $match[1];
                $buffer = [];
                continue;
            }
            // A non-space at column zero ends the jobs block.
            if ($line !== '' && !ctype_space($line[0])) {
                break;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $jobs[$current] = implode("\n", $buffer);
        }

        return $jobs;
    }

    /**
     * A job body split into its step blocks — each from a `- ` list item to the
     * next at the same indent.
     *
     * @return list<string>
     */
    private function steps(string $jobText): array
    {
        $steps = [];
        $current = null;
        $indent = null;
        foreach (explode("\n", $jobText) as $line) {
            if (preg_match('/^(\s+)-\s/', $line, $match) === 1
                && ($indent === null || strlen($match[1]) === $indent)) {
                if ($current !== null) {
                    $steps[] = implode("\n", $current);
                }
                $current = [$line];
                $indent = strlen($match[1]);
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $steps[] = implode("\n", $current);
        }

        return $steps;
    }

    /**
     * Drop `#` line comments so a commented-out step or a `# playwright` note
     * cannot satisfy or trip the check. A `#` that starts a comment is at line
     * start or follows whitespace; one inside a quoted run-command value does
     * not, and is left alone.
     */
    private function stripComments(string $body): string
    {
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            $lines[] = (string) preg_replace('/(^|\s)#.*$/', '$1', $line);
        }

        return implode("\n", $lines);
    }
}
