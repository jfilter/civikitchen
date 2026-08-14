<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * `info.xml` ahead of the newest tag means the release commit was written and
 * never tagged: the version claim exists, the release it describes does not,
 * and every consumer still gets the previous one.
 *
 * A failure rather than a warning — a half-cut release is unambiguously a
 * mistake, not a state of work: the bump is the last step before the tag, and
 * `ckrelease` derives everything it builds from that number.
 *
 * The opposite order (a tag ahead of info.xml) is not judged here: it cannot
 * come out of the pipeline, since `ckrelease check` refuses the tag whose
 * version info.xml does not carry.
 */
final class ReleaseTagCoherenceCheck implements Check
{
    public function name(): string
    {
        return 'release-tag-coherence';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $version = $context->infoVersion();
        if ($version === '') {
            return;
        }

        $history = ReleaseHistory::read($context);
        if (!$history->evaluable($this->name(), $reporter)) {
            return;
        }

        if (version_compare($version, $history->version(), '>')) {
            $reporter->fail(sprintf(
                'info.xml <version> %s is ahead of the newest tag %s — the bump was committed but never tagged, '
                . 'so nothing released carries it (git tag -a v%s && git push origin v%s)',
                $version,
                (string) $history->tag,
                $version,
                $version,
            ));
        }
    }
}
