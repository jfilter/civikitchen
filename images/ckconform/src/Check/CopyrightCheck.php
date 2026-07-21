<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The copyright holder named in `.ckconform` must actually appear in LICENSE.txt.
 * A relicensing that edits info.xml but leaves the licence file naming the
 * previous holder is the failure mode.
 *
 * The match is a literal substring, case-sensitive — a holder's legal name is
 * not a thing to be lenient about. (`grep -F` in the predecessor.)
 */
final class CopyrightCheck implements Check
{
    public function name(): string
    {
        return 'copyright';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $want = $context->policyValue('copyright');
        if ($want === null || !$context->exists('LICENSE.txt')) {
            return;
        }

        if (!str_contains($context->read('LICENSE.txt') ?? '', $want)) {
            $reporter->fail(
                "LICENSE.txt does not name the copyright holder '{$want}' from .ckconform"
            );
        }
    }
}
