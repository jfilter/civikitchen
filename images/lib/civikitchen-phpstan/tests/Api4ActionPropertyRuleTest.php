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

    protected function getRule(): Rule
    {
        return new Api4ActionPropertyRule();
    }
}
