<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * civix scaffolds a composer.json for every extension; its absence usually
 * means a hand-rolled repo that skipped the generator and has no
 * machine-readable metadata (name, autoload, dependencies) at all.
 */
final class ComposerJsonCheck implements Check
{
    public function name(): string
    {
        return 'composer-json';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->exists('composer.json')) {
            $reporter->fail('no composer.json (extension metadata)');
        }
    }
}
