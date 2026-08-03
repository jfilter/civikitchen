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

    public function testHygieneIsSilentOnAConsumedIgnore(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name -- third-party hook, catalogued upstream
            function fixture_civicrm_postCommmit($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
        $this->assertSilent($this->run_(new SuppressionHygieneCheck(), $context));
    }

    public function testHygieneWarnsOnALineIgnoreThatSuppressedNothing(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name -- the typo this covered is fixed
            function fixture_civicrm_postCommit($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
        $this->assertWarns($this->run_(new SuppressionHygieneCheck(), $context), 'suppressed nothing');
    }

    public function testHygieneWarnsOnAFileIgnoreThatSuppressedNothing(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore-file hook-dispatch-name -- the legacy hooks are gone
            function fixture_civicrm_postCommit($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
        $this->assertWarns($this->run_(new SuppressionHygieneCheck(), $context), 'ckconform-ignore-file');
    }

    public function testHygieneIsSilentOnAnIgnoreForARepoSkippedCheck(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name -- would it match? nobody looked
            function fixture_civicrm_postCommit($op) {
            }
            PHP,
        ]);
        // ignore_checks= skipped the check, so the run never looked for the
        // finding — unused cannot be concluded from a check that did not run.
        $context->skipChecks(['hook-dispatch-name']);
        $this->assertSilent($this->run_(new SuppressionHygieneCheck(), $context));
    }

    public function testHygieneReportsOnlyTheUnconsumedNameOfAMultiNameIgnore(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            // ckconform-ignore hook-dispatch-name, hook-style -- one still needed
            function fixture_civicrm_postCommmit($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
        $reporter = $this->run_(new SuppressionHygieneCheck(), $context);
        $this->assertWarns($reporter, "for 'hook-style' suppressed nothing");
        self::assertStringNotContainsString('hook-dispatch-name', implode("\n", $reporter->messages('warn')));
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
