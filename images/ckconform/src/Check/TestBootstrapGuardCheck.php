<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A headless suite rebuilds the database it is pointed at, so a bootstrap
 * without the TEST_DB_DSN guard can wipe the developer's own CiviCRM while the
 * test run reports green.
 *
 * Only Civi\Test rebuilds anything, so a pure unit suite (plain TestCase, no
 * CiviCRM boot) has nothing to guard and demanding the guard there would be
 * noise. The markers are searched across the whole tests/ tree, not just
 * bootstrap.php, because the interfaces are implemented in the test cases.
 */
final class TestBootstrapGuardCheck implements Check
{
    private const MARKERS = [
        'HeadlessInterface',
        'TransactionalInterface',
        'Civi\Test\\',
    ];

    public function name(): string
    {
        return 'test-bootstrap-guard';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!is_dir($context->path('tests/phpunit'))) {
            return;
        }

        if (!$context->exists('tests/phpunit/bootstrap.php')) {
            $reporter->fail('no tests/phpunit/bootstrap.php');

            return;
        }

        if (!$this->usesCiviTest($context)) {
            $reporter->ok('unit-only suite (no Civi\\Test) — no test-database guard needed');

            return;
        }

        if (str_contains($context->read('tests/phpunit/bootstrap.php') ?? '', 'TEST_DB_DSN')) {
            $reporter->ok('test bootstrap has the TEST_DB_DSN guard');
        } else {
            $reporter->fail('tests/phpunit/bootstrap.php lacks the TEST_DB_DSN guard — headless runs can wipe the dev DB');
        }
    }

    private function usesCiviTest(Context $context): bool
    {
        foreach ($context->findFiles('tests') as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach (self::MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }
}
