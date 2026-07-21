<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * cklint needs a project anchor: without phpcs.xml.dist it falls back to
 * whatever standard happens to be configured, so a repo can look linted while
 * running rules nobody chose.
 *
 * The file is XML, so it is read as XML — the bash version grepped for the
 * literal string ref="CiviKitchen", which also matched it inside a comment or
 * inside an <exclude>.
 */
final class PhpcsConfigCheck implements Check
{
    public function name(): string
    {
        return 'phpcs-config';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $file = null;
        foreach (['phpcs.xml.dist', 'phpcs.xml'] as $candidate) {
            if ($context->exists($candidate)) {
                $file = $candidate;
                break;
            }
        }

        if ($file === null) {
            $reporter->fail('no phpcs.xml.dist (cklint has no project anchor)');

            return;
        }

        if ($this->referencesCiviKitchen($context->read($file) ?? '')) {
            $reporter->ok('phpcs config references the CiviKitchen standard');
        } else {
            $reporter->fail('phpcs config exists but does not <rule ref="CiviKitchen"/>');
        }
    }

    private function referencesCiviKitchen(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($parsed === false) {
            return false;
        }

        foreach ($parsed->xpath('//rule[@ref]') ?: [] as $rule) {
            $ref = (string) $rule['ref'];
            // 'CiviKitchen' or 'CiviKitchen.Some.Sniff' — but not a project
            // standard that merely happens to start with those letters.
            if ($ref === 'CiviKitchen' || str_starts_with($ref, 'CiviKitchen.')) {
                return true;
            }
        }

        return false;
    }
}
