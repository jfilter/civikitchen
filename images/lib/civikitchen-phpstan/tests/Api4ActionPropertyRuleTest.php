<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4ActionPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<Api4ActionPropertyRule>
 */
final class Api4ActionPropertyRuleTest extends RuleTestCase
{
    private bool $strict = false;

    /**
     * The shipped default stays quiet about `?string $x;` — the reading in
     * the strict test is the same file, so the two halves cannot drift.
     */
    public function testParametersThatFailAsPhpErrorsInsteadOfApiErrors(): void
    {
        $this->analyse(
            [__DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-action-properties.php'],
            [
                [
                    'APIv4 action parameter $channel is typed string with no default and no @required — a caller that '
                    . 'omits it gets "must not be accessed before initialization" instead of an API validation error. '
                    . 'Add @required, or give it a default.',
                    25,
                ],
                [
                    'APIv4 action parameter $retries is marked @required but has a default, so the kernel never sees '
                    . 'it missing and the requirement is never enforced.',
                    28,
                ],
                [
                    'APIv4 action parameter $locale is marked @required but is nullable, so the kernel never sees it '
                    . 'missing and the requirement is never enforced.',
                    31,
                ],
            ],
        );
    }

    public function testStrictActionParamsAddsTheNullableWithoutDefaultReport(): void
    {
        $this->strict = true;
        $this->analyse(
            [__DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-action-properties.php'],
            [
                [
                    'APIv4 action parameter $channel is typed string with no default and no @required — a caller that '
                    . 'omits it gets "must not be accessed before initialization" instead of an API validation error. '
                    . 'Add @required, or give it a default.',
                    25,
                ],
                [
                    'APIv4 action parameter $retries is marked @required but has a default, so the kernel never sees '
                    . 'it missing and the requirement is never enforced.',
                    28,
                ],
                [
                    'APIv4 action parameter $locale is marked @required but is nullable, so the kernel never sees it '
                    . 'missing and the requirement is never enforced.',
                    31,
                ],
                [
                    'APIv4 action parameter $signature is nullable with no default, so it is uninitialized rather than '
                    . 'null until the kernel writes it — give it a default of null to make that explicit.',
                    34,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new Api4ActionPropertyRule($this->strict);
    }
}
