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
 * The bash version grepped for the literal '<coverage', so a commented-out
 * `<!-- <coverage> -->` satisfied it while phpunit measured nothing — exactly
 * the failure mode the gate exists to catch. The config is XML, so it is parsed
 * as XML and a real element is required.
 */
final class CoverageSectionCheck implements Check
{
    public function name(): string
    {
        return 'coverage-section';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!is_dir($context->path('tests/phpunit'))) {
            return;
        }

        foreach (['phpunit.xml.dist', 'phpunit.xml'] as $candidate) {
            if ($this->declaresCoverage($context->read($candidate))) {
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
