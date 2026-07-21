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
        if ($context->trackedUnder('tests/phpunit') === []) {
            return;
        }

        if (!$context->isTracked('tests/phpunit/bootstrap.php')) {
            $reporter->fail('no tests/phpunit/bootstrap.php');

            return;
        }

        if (!$this->usesCiviTest($context)) {
            $reporter->ok('unit-only suite (no Civi\\Test) — no test-database guard needed');

            return;
        }

        if ($this->guardsInCode($context->read('tests/phpunit/bootstrap.php') ?? '')) {
            $reporter->ok('test bootstrap has the TEST_DB_DSN guard');
        } else {
            $reporter->fail('tests/phpunit/bootstrap.php lacks the TEST_DB_DSN guard — headless runs can wipe the dev DB');
        }
    }

    private function usesCiviTest(Context $context): bool
    {
        foreach ($context->trackedUnder('tests') as $file) {
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

    /**
     * Whether the bootstrap actually executes the guard, rather than describing
     * it in a comment or merely naming the constant.
     *
     * Two stages of tightening, both from real passes on unguarded files. First
     * the raw source matched, so three explanatory comments naming TEST_DB_DSN
     * read as "has the guard" — comments are stripped now. But a bare mention in
     * code (`$x = getenv('TEST_DB_DSN');`) is still not a guard: a guard reads
     * the value AND stops the run when it is missing. So the code must both name
     * TEST_DB_DSN and reach a terminating statement — every real bootstrap in
     * the estate does (throw / exit / die). The read itself is left loose on
     * purpose: herald's guard does not getenv() it, it pulls TEST_DB_DSN out of
     * a decoded ~/.cv.json, and that is a valid guard.
     */
    private function guardsInCode(string $source): bool
    {
        if ($source === '') {
            return false;
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        if (!str_contains($code, 'TEST_DB_DSN')) {
            return false;
        }

        return preg_match('/\b(throw\s+new\b|exit\s*\(|die\s*\()/', $code) === 1;
    }
}
