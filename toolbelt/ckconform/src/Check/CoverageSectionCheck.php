<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Coverage has to be measurable before it can be demanded: without a <coverage>
 * section `phpunit --coverage-text` reports on nothing, which reads like a
 * passing gate.
 *
 * The config is parsed as XML and a real element is required: a commented-out
 * `<!-- <coverage> -->` must not satisfy the gate.
 */
final class CoverageSectionCheck implements Check
{
    public function name(): string
    {
        return 'coverage-section';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        foreach (['phpunit.xml.dist', 'phpunit.xml'] as $candidate) {
            if ($this->declaresCoverage($context->readShipped($candidate))) {
                $reporter->ok('phpunit config declares coverage sources');

                return;
            }
        }

        $reporter->fail('phpunit config has no <coverage> section — coverage runs measure nothing');
    }

    private function declaresCoverage(?string $xml): bool
    {
        if ($xml === null || trim($xml) === '') {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($parsed === false) {
            return false;
        }

        return ($parsed->xpath('//coverage') ?: []) !== [];
    }
}
