<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\DistPaths;
use CiviKitchen\Ckconform\Reporter;

/**
 * Shipped code that sits behind the newest tag reaches no site: every install
 * gets the tag, so a fix committed after it exists only for whoever reads the
 * branch.
 *
 * Two things keep it from being noise. Only changes that land in the release
 * archive count — the development layer (tests/, .github/, docs) never reaches
 * an install, and what does is DistPaths', not this check's, definition. And
 * unreleased work is the normal state of a repo, so the finding is about age:
 * it fires once the oldest such commit has been waiting longer than
 * `max_unreleased_days`.
 *
 * A warning: when to cut a release is a judgement about the change, and a
 * failing build cannot make it.
 */
final class UnreleasedShippedChangesCheck implements Check
{
    private const DEFAULT_MAX_DAYS = 30;

    public function name(): string
    {
        return 'unreleased-shipped-changes';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $maxDays = $this->maxDays($context, $reporter);
        if ($maxDays === null) {
            return;
        }

        $history = ReleaseHistory::read($context);
        if (!$history->evaluable($this->name(), $reporter)) {
            return;
        }

        $tag = (string) $history->tag;
        $commits = $context->commitsSince($tag);
        if ($commits === null) {
            $reporter->warn("{$this->name()} not evaluated: git could not read {$tag}..HEAD");

            return;
        }

        $excluded = DistPaths::excluded($context);
        $oldest = null;
        foreach ($commits as $commit) {
            foreach ($commit['files'] as $file) {
                if (DistPaths::ships($file, $excluded)) {
                    // The log is newest first, so the last match is the oldest.
                    $oldest = $commit;
                    break;
                }
            }
        }
        if ($oldest === null) {
            return;
        }

        $age = $this->ageInDays($oldest['date']);
        if ($age === null || $age < $maxDays) {
            return;
        }

        $reporter->warn(sprintf(
            'shipped code has been unreleased for %d days — %s..HEAD changes what an install gets, '
            . 'oldest %s (%s), and no site has any of it until the next tag',
            $age,
            $tag,
            substr($oldest['hash'], 0, 8),
            substr($oldest['date'], 0, 10),
        ));
    }

    /** The repo's threshold, or null when it declared one that is not a number. */
    private function maxDays(Context $context, Reporter $reporter): ?int
    {
        $declared = $context->policyValue('max_unreleased_days');
        if ($declared === null) {
            return self::DEFAULT_MAX_DAYS;
        }
        if (preg_match('/^[1-9]\d*$/', $declared) !== 1) {
            $reporter->fail(
                ".ckconform: max_unreleased_days must be a whole number of days, got '{$declared}'"
            );

            return null;
        }

        return (int) $declared;
    }

    private function ageInDays(string $isoDate): ?int
    {
        $timestamp = strtotime($isoDate);

        return $timestamp === false ? null : intdiv(time() - $timestamp, 86400);
    }
}
