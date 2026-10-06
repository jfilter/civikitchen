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
 * `<!-- <coverage> -->` must not satisfy the gate. ckcoverage needs the
 * <coverage> element itself; the sources may sit in it or, from PHPUnit 10, in <source>.
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

        $verdicts = [];
        foreach (['phpunit.xml.dist', 'phpunit.xml'] as $candidate) {
            $verdict = $this->coverage($context->readShipped($candidate));
            if ($verdict === 'sources') {
                $reporter->ok('phpunit config declares coverage sources');

                return;
            }
            $verdicts[] = $verdict;
        }

        $reporter->fail(in_array('empty', $verdicts, true)
            ? 'phpunit config\'s <coverage> lists no sources (<coverage><include> or, from PHPUnit 10, <source><include>) — coverage runs measure nothing'
            : 'phpunit config has no <coverage> section — coverage runs measure nothing');
    }

    /** 'sources', 'empty' for a <coverage> without an include list, or 'none'. */
    private function coverage(?string $xml): string
    {
        if ($xml === null || trim($xml) === '') {
            return 'none';
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($parsed === false || ($parsed->xpath('//coverage') ?: []) === []) {
            return 'none';
        }
        $sources = '/*/*[self::coverage or self::source]/include/*[self::directory or self::file]';

        return ($parsed->xpath($sources) ?: []) !== [] ? 'sources' : 'empty';
    }
}
