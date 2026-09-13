<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * A release is two halves: the commit that bumps info.xml `<version>`, then
 * the `v<version>` tag (docs/extension-releases.md). This rule is about the
 * versions the repo has already moved PAST — every one of them was announced
 * as a release and only the tag makes it installable, so a number that came
 * and went untagged is a release that exists nowhere but in the history.
 *
 * The current version is deliberately not judged: between the bump commit and
 * the tag it is legitimately untagged, and release-tag-coherence owns that
 * window.
 *
 * An entry of `policy.untagged_versions` that the history does not need is
 * reported as stale, in three shapes: the version carries a tag after all, the
 * version is the one info.xml carries right now, or info.xml never carried it.
 *
 * The opt-out is `policy.release: none` with its reason — a repo that cuts no
 * releases has no tags to miss. For a single number the repo passed through and
 * will never publish — a bump that was re-scoped or taken back — the narrow
 * escape is `policy.untagged_versions`, one entry per version with its reason.
 * Tagging such a version after the fact is not the fix: the tag push runs the
 * release workflow and publishes the code of that moment.
 */
final class ReleaseTagsCheck implements Check
{
    public function name(): string
    {
        return 'release-tags';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $declared = $context->policyValue('release');
        if ($declared !== null) {
            // A repo that cuts no releases has no tag to miss, so every entry
            // in the list is inert — and nothing else here would say so.
            if ($context->policyValues('untagged_versions') !== []) {
                $reporter->warn(
                    'civikitchen.yaml: untagged_versions is set while release is none — a repo that cuts no '
                    . 'releases has no missing tag to excuse; drop the list'
                );
            }

            // release-workflow reports a value that is not the documented
            // opt-out; repeating that here would double the finding.
            return;
        }

        if (!$context->isGitRepo()) {
            return;
        }
        if ($context->isShallowClone()) {
            $reporter->warn(
                "{$this->name()} not evaluated: shallow clone — neither the tags nor the history of info.xml "
                . 'is complete here, and a truncated history cannot tell an untagged version from an old one '
                . '(a workflow needs fetch-depth: 0 and fetch-tags: true)'
            );

            return;
        }

        $current = $context->infoVersion();
        if ($current === '') {
            return;
        }

        $earlier = array_values(array_filter(
            $context->infoVersionHistory(),
            static fn (string $version): bool => $version !== $current,
        ));
        $tags = $context->tags();
        $untagged = array_values(array_filter(
            $earlier,
            static fn (string $version): bool => !in_array('v' . $version, $tags, true),
        ));

        $declaredUntagged = [];
        foreach ($context->policyValues('untagged_versions') as $value) {
            $version = Policy::stripReason($value);
            $declaredUntagged[] = $version;
            if (in_array($version, $untagged, true)) {
                continue;
            }
            if ($version === $current) {
                $reporter->warn(sprintf(
                    'civikitchen.yaml: untagged_versions lists %s, the version info.xml carries right now — '
                    . 'release-tag-coherence owns the window between the bump commit and the tag, so the entry '
                    . 'excuses nothing yet',
                    $version,
                ));

                continue;
            }
            $reporter->warn(sprintf(
                'civikitchen.yaml: untagged_versions lists %s, %s — remove the stale exception',
                $version,
                in_array('v' . $version, $tags, true)
                    ? 'but v' . $version . ' exists'
                    : 'a version info.xml has not moved past',
            ));
        }

        $untagged = array_values(array_filter(
            $untagged,
            static fn (string $version): bool => !in_array($version, $declaredUntagged, true),
        ));
        if ($untagged === []) {
            return;
        }

        $missing = implode(', ', array_map(static fn (string $version): string => 'v' . $version, $untagged));
        if ($tags === []) {
            $absent = 'this repo has no v* tag at all';
        } else {
            $absent = count($untagged) === 1 ? "no $missing exists" : "none of $missing exists";
        }

        $reporter->fail(sprintf(
            'info.xml <version> moved past %s and %s — the bump was committed, the tag never cut, '
            . 'so nothing installable carries %s; see docs/extension-releases.md',
            implode(', ', $untagged),
            $absent,
            count($untagged) === 1 ? 'that version' : 'those versions',
        ));
    }
}
