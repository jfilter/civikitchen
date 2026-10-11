<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * cklint lints with mago and no longer reads a phpcs config, so a leftover one
 * holds project rules that nothing enforces any more.
 */
final class PhpcsConfigCheck implements Check
{
    public function name(): string
    {
        return 'phpcs-config';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach (['phpcs.xml.dist', 'phpcs.xml'] as $file) {
            if ($context->exists($file)) {
                $reporter->fail("{$file} is no longer read: cklint lints with mago. Move project rules to a mago.toml "
                    . 'that extends the CiviKitchen baseline (docs/extension-standards.md), then delete the file');

                return;
            }
        }

        $reporter->ok('no phpcs config (cklint lints with mago)');
    }
}
