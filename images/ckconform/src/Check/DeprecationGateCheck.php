<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Runtime deprecations are only a test failure if the suite is configured to
 * make them one. Core signals them with trigger_error(..., E_USER_DEPRECATED)
 * (CRM_Core_Error::deprecatedWarning / deprecatedFunctionWarning), and PHPUnit
 * turns that into a Deprecated exception only with
 * convertDeprecationsToExceptions="true". Measured on 6.x/PHPUnit 9.6: with the
 * attribute the call errors the test, without it the run is green and the
 * message ends up in the PHP log nobody reads.
 *
 * The attribute alone is not the whole gate. PHPUnit's error handler bails out
 * when the error is outside error_reporting(), and the CLI default masks
 * E_DEPRECATED (22527). Civi\Test\CiviTestListener raises it to E_ALL, but only
 * for tests it recognises — a plain unit test runs under the ini default, where
 * engine-level deprecations ("Passing null to parameter #1 ... is deprecated",
 * the PHP-version signal) are swallowed. So the bootstrap has to widen the mask
 * too.
 */
final class DeprecationGateCheck implements Check
{
    private const CONFIGS = ['phpunit.xml.dist', 'phpunit.xml'];

    public function name(): string
    {
        return 'deprecation-gate';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!is_dir($context->path('tests/phpunit'))) {
            return;
        }

        $config = null;
        foreach (self::CONFIGS as $candidate) {
            if ($context->exists($candidate)) {
                $config = $candidate;
                break;
            }
        }
        if ($config === null) {
            // PhpunitConfigCheck already fails on the missing config.
            return;
        }

        $xml = $context->read($config) ?? '';
        if (!$this->convertsDeprecations($xml)) {
            $reporter->warn(
                $config . ' does not set convertDeprecationsToExceptions="true"'
                . ' — CiviCRM runtime deprecations pass the suite silently'
            );
        }

        if (!$this->widensErrorReporting($xml, $context->read('tests/phpunit/bootstrap.php'))) {
            $reporter->warn(
                'no error_reporting(E_ALL) in tests/phpunit/bootstrap.php'
                . ' — the CLI default masks E_DEPRECATED outside Civi\\Test tests'
            );
        }
    }

    private function convertsDeprecations(string $xml): bool
    {
        $parsed = $this->parse($xml);
        if ($parsed === null) {
            return false;
        }

        return in_array(strtolower((string) ($parsed['convertDeprecationsToExceptions'] ?? '')), ['true', '1'], true);
    }

    /**
     * Either the bootstrap widens the mask at runtime, or the config does it
     * declaratively via <php><ini name="error_reporting">. Both were seen to
     * work; the template uses the bootstrap because civicrm.settings.php runs
     * after PHPUnit applies its ini settings.
     */
    private function widensErrorReporting(string $xml, ?string $bootstrap): bool
    {
        if ($bootstrap !== null && preg_match('/\berror_reporting\s*\(\s*(E_ALL|-\s*1)/', $bootstrap) === 1) {
            return true;
        }

        $parsed = $this->parse($xml);
        foreach ($parsed?->xpath('//php/ini') ?: [] as $ini) {
            if ((string) $ini['name'] === 'error_reporting') {
                return true;
            }
        }

        return false;
    }

    private function parse(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        return $parsed === false ? null : $parsed;
    }
}
