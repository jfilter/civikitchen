<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tests/phpunit directory without phpunit.xml.dist is a suite nobody can run
 * the same way twice: every developer and every CI job supplies its own
 * defaults, so "the tests pass" means something different each time.
 */
final class PhpunitConfigCheck implements Check
{
    public function name(): string
    {
        return 'phpunit-config';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!is_dir($context->path('tests/phpunit'))) {
            return;
        }

        if (!$context->exists('phpunit.xml.dist')) {
            $reporter->fail('tests/phpunit exists but no phpunit.xml.dist');
        }
    }
}
