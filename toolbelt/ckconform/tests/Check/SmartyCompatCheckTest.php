<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\SmartyCompatCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class SmartyCompatCheckTest extends CheckTestCase
{
    public function testAModernTemplateIsSilent(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {foreach from=$rows item=row}
                  <p>{$row.label|escape}</p>
                {/foreach}
                {section name=i loop=$rows}{$smarty.section.i.index}{/section}
                <p>{$total|crmMoney}</p>
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    public function testAPhpBlockWarnsWithItsLine(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                <h1>hello</h1>
                {php}echo 1;{/php}
                TPL,
        ], git: true);
        $reporter = $this->run_(new SmartyCompatCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'templates/CRM/Greeter/Page/Foo.tpl:2: {php}');
    }

    /**
     * One block is one finding — the closing {/php} must not be counted again.
     */
    public function testAPhpBlockIsReportedOnce(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{php}\necho 1;\n{/php}\n",
        ], git: true);
        $reporter = $this->run_(new SmartyCompatCheck(), $context);
        self::assertSame(1, $reporter->warnings(), $reporter->render());
    }

    public function testIncludePhpAndInsertWarn(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {include_php file="lib/helper.php"}
                {insert name="banner" script="banner.php"}
                TPL,
        ], git: true);
        $reporter = $this->run_(new SmartyCompatCheck(), $context);
        self::assertSame(2, $reporter->warnings(), $reporter->render());
        $this->assertWarns($reporter, '{include_php}');
        $this->assertWarns($reporter, '{insert}');
    }

    public function testPopupTagsWarn(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{popup_init src=\"popup.js\"}\n{popup text=\"hi\"}\n",
        ], git: true);
        $reporter = $this->run_(new SmartyCompatCheck(), $context);
        self::assertSame(2, $reporter->warnings(), $reporter->render());
    }

    /**
     * Smarty 5 renders a {literal} body as text — verified against the Smarty 5
     * core ships — so a tag in there never reaches the tag compiler.
     */
    public function testAPhpTagInsideLiteralIsNotAFinding(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {literal}
                <pre>{php}echo 1;{/php}</pre>
                {/literal}
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    public function testATagInsideASmartyCommentIsNotAFinding(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{* old draft: {insert name=\"x\"} *}\n<p>ok</p>\n",
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    /**
     * auto_literal: "{ php}" is plain text to Smarty, so it is plain text here.
     * Neither is a longer tag that merely starts with a flagged name.
     */
    public function testNearMissesAreNotFindings(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                <p>{ php}</p>
                {inserted name="x"}
                {phpinfo_link}
                <p>write {ldelim}php{rdelim} to show the tag</p>
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    public function testTestAndVendorTemplatesAreNotJudged(): void
    {
        $context = $this->repo([
            'tests/fixtures/legacy.tpl' => "{php}echo 1;{/php}\n",
            'vendor/foo/bar/old.tpl' => "{insert name=\"x\"}\n",
            'node_modules/foo/old.tpl' => "{insert name=\"x\"}\n",
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    /**
     * A repo that ships block.php.php has registered {php} itself, and
     * registerPlugin() makes the tag compile again.
     */
    public function testARepoRegisteredTagIsExempt(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{insert name=\"banner\"}\n",
            'CRM/Greeter/Smarty/plugins/function.insert.php' => "<?php\nfunction smarty_function_insert() {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    public function testAnIgnoreCommentWithAReasonSuppresses(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {* ckconform-ignore smarty-compat -- kept for the Smarty 4 branch *}
                {php}echo 1;{/php}
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    public function testAnIgnoreCommentOnTheSameLineSuppresses(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' =>
                "{php}echo 1;{/php} {* ckconform-ignore smarty-compat -- legacy report, removed in v2 *}\n",
        ], git: true);
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }

    /**
     * An unexplained silencer is indistinguishable from a forgotten one, so it
     * suppresses nothing and is reported itself.
     */
    public function testAnIgnoreCommentWithoutAReasonSuppressesNothing(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{* ckconform-ignore smarty-compat *}\n{php}echo 1;{/php}\n",
        ], git: true);
        $reporter = $this->run_(new SmartyCompatCheck(), $context);
        $this->assertWarns($reporter, 'has no reason');
        $this->assertWarns($reporter, '{php}');
        self::assertSame(2, $reporter->warnings(), $reporter->render());
    }

    /**
     * The marker is per check name: another check's ignore is not this one's.
     */
    public function testAnIgnoreForAnotherCheckDoesNotSuppress(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "{* ckconform-ignore crm-scope -- unrelated *}\n{php}echo 1;{/php}\n",
        ], git: true);
        $this->assertWarns($this->run_(new SmartyCompatCheck(), $context), '{php}');
    }

    /**
     * Untracked files cannot break anyone else's build, so they are not judged
     * in a git repo.
     */
    public function testAnUntrackedTemplateIsNotJudged(): void
    {
        $context = $this->repo([
            'templates/CRM/Greeter/Page/Foo.tpl' => "<p>ok</p>\n",
        ], git: true);
        file_put_contents(
            $context->path('templates/CRM/Greeter/Page/Scratch.tpl'),
            "{php}echo 1;{/php}\n"
        );
        $this->assertSilent($this->run_(new SmartyCompatCheck(), $context));
    }
}
