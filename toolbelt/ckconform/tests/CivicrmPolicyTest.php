<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Policy;

/**
 * policy.civicrm names the CiviCRM minor the shared workflows pick the stack
 * image by, so it has to survive as the exact string the repo wrote.
 */
final class CivicrmPolicyTest extends CheckTestCase
{
    public function testAQuotedMinorKeepsItsTrailingZero(): void
    {
        self::assertSame(['6.10'], Policy::parse("version: 1\npolicy:\n  civicrm: \"6.10\"\n")['civicrm']);
    }

    public function testAnUnquotedMinorIsRejectedRatherThanReadAsANumber(): void
    {
        $this->expectException(\RuntimeException::class);
        Policy::parse("version: 1\npolicy:\n  civicrm: 6.10\n");
    }

    public function testAValueThatIsNoMinorIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        Policy::parse("version: 1\npolicy:\n  civicrm: \"6.17.2\"\n");
    }
}
