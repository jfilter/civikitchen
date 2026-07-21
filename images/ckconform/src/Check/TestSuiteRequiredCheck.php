<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A repo with real PHP logic and no suite is a gap, not a style preference.
 * Config-only extensions can say so in .ckconform: tests=optional -- <reason>
 *
 * The source count is the delicate part: it once missed the extension's own
 * root .php file, which is exactly where a config-only extension keeps whatever
 * logic it has, so such a repo looked like it had nothing to cover. Counted are
 * all .php under Civi/ and CRM/ plus the .php directly in the repo root;
 * generated and machine-owned files (.civix.php, DAO/, BAO/,
 * phpstanBootstrap.php) do not count as surface anyone is asked to test.
 */
final class TestSuiteRequiredCheck implements Check
{
    private const EXCLUDED = [
        '.civix.php',
        '/DAO/',
        '/BAO/',
        'phpstanBootstrap.php',
    ];

    public function name(): string
    {
        return 'test-suite-required';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (is_dir($context->path('tests/phpunit'))) {
            return;
        }

        $optout = $context->policyValue('tests');
        if ($optout !== null) {
            $reporter->ok("no test suite — declared optional in .ckconform ({$optout})");

            return;
        }

        $count = count($this->sourceFiles($context));
        if ($count > 0) {
            $reporter->fail("no test suite (tests/phpunit) but {$count} PHP source file(s) — add tests, or declare 'tests=optional -- <reason>' in .ckconform");
        } else {
            $reporter->ok('no test suite — no PHP source to cover');
        }
    }

    /** @return list<string> */
    private function sourceFiles(Context $context): array
    {
        $candidates = array_merge(
            $context->trackedUnder('Civi', ['.php']),
            $context->trackedUnder('CRM', ['.php']),
            $this->rootFiles($context),
        );

        return array_values(array_filter(
            $candidates,
            static function (string $file): bool {
                foreach (self::EXCLUDED as $needle) {
                    if (str_contains($file, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * The repo root only, not recursively — everything below the root is either
     * already counted via Civi//CRM/ or deliberately out of scope (tests/, ang/,
     * vendor/). Tracked, not on-disk: an untracked <key>.php lying in the root
     * must not turn a config-only repo into one that "has source to cover".
     *
     * @return list<string>
     */
    private function rootFiles(Context $context): array
    {
        $files = [];
        foreach ($context->trackedUnder('', ['.php']) as $file) {
            if (!str_contains($file, '/')) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }
}
