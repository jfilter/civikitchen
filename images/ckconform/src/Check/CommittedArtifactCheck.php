<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * node_modules, vendor and the phpunit result cache are machine-owned: every
 * run regenerates them, and every regeneration diverges from what the last
 * commit checked in. A committed copy is stale by construction.
 *
 * Checked as both a tracked file of that exact name and a tracked directory
 * of that name anywhere in the tree, top-level or nested — matching the bash
 * predecessor's two `git ls-files` pathspecs: "$bad/" and a glob for $bad
 * nested under another directory.
 */
final class CommittedArtifactCheck implements Check
{
    private const ARTIFACTS = ['.phpunit.result.cache', 'node_modules', 'vendor'];

    /**
     * Suffix-matched artifacts, for caches named after the file they belong to.
     * TypeScript writes <tsconfig-name>.tsbuildinfo, which is why inflow's
     * '.tsbuildinfo' ignore pattern never matched and the cache ended up tracked.
     */
    private const ARTIFACT_SUFFIXES = ['.tsbuildinfo'];

    public function name(): string
    {
        return 'committed-artifact';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach (self::ARTIFACT_SUFFIXES as $suffix) {
            foreach ($context->trackedFiles() as $file) {
                if (str_ends_with($file, $suffix)) {
                    $reporter->fail("build/cache artifact committed: {$file}");
                    break;
                }
            }
        }

        foreach (self::ARTIFACTS as $bad) {
            if ($this->isCommitted($context, $bad)) {
                $reporter->fail("build/cache artifact committed: {$bad}");
            }
        }
    }

    private function isCommitted(Context $context, string $bad): bool
    {
        if ($context->isTracked($bad)) {
            return true;
        }

        foreach ($context->trackedFiles() as $file) {
            if (str_starts_with($file, "{$bad}/") || str_contains($file, "/{$bad}/")) {
                return true;
            }
        }

        return false;
    }
}
