<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HookDispatchNameCheck;
use CiviKitchen\Ckconform\Check\HookStyleCheck;
use CiviKitchen\Ckconform\Check\SuppressionHygieneCheck;
use CiviKitchen\Ckconform\Suppressions;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class SuppressionTest extends CheckTestCase
{
    public function testALineIgnoreSilencesTheFindingOnTheNextLine(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name -- third-party hook, catalogued upstream
            function fixture_civicrm_postCommmit($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testAnIgnoreForAnotherCheckDoesNotSilence(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-style -- wrong check on purpose
            function fixture_civicrm_postCommmit($op) {
            }
            PHP,
        ]);
        $this->assertWarns($this->run_(new HookDispatchNameCheck(), $context), 'postCommmit');
    }

    public function testAFileIgnoreSilencesEveryFindingInTheFile(): void
    {
        $context = $this->repo([
            '.ckconform' => "hook_style=listener\n",
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>scan-classes@1.0.0</mixin></mixins>'),
            'fixture.php' => <<<'PHP'
                <?php
                // ckconform-ignore-file hook-style -- legacy main file, migrates with the v2 rewrite
                function fixture_civicrm_post($op) {
                }
                function fixture_civicrm_alterMailParams(&$params) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testAMarkerInsideAStringSuppressesNothing(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            $doc = 'ckconform-ignore hook-dispatch-name -- not a comment';
            function fixture_civicrm_postCommmit($op) {
            }
            PHP,
        ]);
        $this->assertWarns($this->run_(new HookDispatchNameCheck(), $context), 'postCommmit');
    }

    public function testHygieneWarnsOnAMissingReason(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name
            function fixture_civicrm_config(&$config) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new SuppressionHygieneCheck(), $context);
        $this->assertWarns($reporter, 'without a reason');
    }

    public function testHygieneWarnsOnAnUnknownCheckName(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispach-name -- suppresses a typo'd check
            function fixture_civicrm_config(&$config) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new SuppressionHygieneCheck(), $context);
        $this->assertWarns($reporter, "unknown check 'hook-dispach-name'");
    }

    public function testHygieneIsSilentOnAWellFormedIgnore(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name, hook-style -- both judged elsewhere
            function fixture_civicrm_config(&$config) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new SuppressionHygieneCheck(), $context));
    }

    public function testCommaListAndTrailingCommentForms(): void
    {
        $s = Suppressions::of(<<<'PHP'
            <?php
            function a() {} // ckconform-ignore alpha, beta -- trailing form
            /* ckconform-ignore-file gamma -- block form */
            PHP);
        self::assertTrue($s->suppressed('alpha', 2));
        self::assertTrue($s->suppressed('beta', 2));
        self::assertFalse($s->suppressed('alpha', 4));
        self::assertTrue($s->suppressed('gamma', 99));
        self::assertSame([], $s->missingReason());
    }
}
