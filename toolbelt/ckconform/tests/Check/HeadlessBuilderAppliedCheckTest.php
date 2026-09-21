<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HeadlessBuilderAppliedCheck;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class HeadlessBuilderAppliedCheckTest extends CheckTestCase
{
    public function testSilentWithoutTests(): void
    {
        $context = $this->repo(['Civi/Ext/Thing.php' => "<?php\nclass Thing {}\n"], git: true);
        $this->assertSilent($this->run_(new HeadlessBuilderAppliedCheck(), $context));
    }

    public function testAppliedHelperPasses(): void
    {
        $this->assertSilent($this->check('return ck_headless()->apply();'));
    }

    public function testAppliedChainWithFixturesPasses(): void
    {
        $this->assertSilent($this->check("return ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();"));
    }

    /** The real-world defect: the builder is returned and the listener drops it. */
    public function testUnappliedHelperFails(): void
    {
        $reporter = $this->check('return ck_headless();');
        $this->assertFails($reporter, 'tests/phpunit/Civi/Ext/PullTest.php:6');
        $this->assertFails($reporter, 'never applied');
    }

    public function testChainEndingBeforeApplyFails(): void
    {
        $this->assertFails($this->check("return ck_headless()->sqlFile('x');"), 'never applied');
    }

    public function testUnappliedCoreBuilderFails(): void
    {
        $this->assertFails($this->check('return \Civi\Test::headless()->installMe(__DIR__);'), 'never applied');
    }

    public function testApplyInATrailingCommentDoesNotCount(): void
    {
        $this->assertFails($this->check('return ck_headless(); // ->apply()'), 'never applied');
    }

    public function testApplyInAStringDoesNotCount(): void
    {
        $this->assertFails($this->check("return ck_headless()->sqlFile('->apply()');"), 'never applied');
    }

    public function testBuilderAppliedThroughItsVariablePasses(): void
    {
        $this->assertSilent($this->check(
            "\$b = ck_headless()->install('x'); if (\$y) { \$b->install('y'); } return \$b->apply();"
        ));
    }

    public function testBuilderVariableNeverAppliedFails(): void
    {
        $this->assertFails($this->check("\$b = ck_headless(); \$b->install('y'); return \$b;"), 'never applied');
    }

    public function testApplyOnAnotherVariableDoesNotCount(): void
    {
        $this->assertFails($this->check("\$b = ck_headless(); return \$c->apply();"), 'never applied');
    }

    public function testImportedCoreBuilderFails(): void
    {
        $this->assertFails($this->check('return Test::headless()->installMe(__DIR__);', 'use Civi\Test;'), 'never applied');
    }

    public function testAliasedCoreBuilderFails(): void
    {
        $this->assertFails($this->check('return T::headless();', 'use Civi\Test as T;'), 'never applied');
    }

    public function testImportedCoreBuilderAppliedPasses(): void
    {
        $this->assertSilent($this->check('return T::headless()->apply();', 'use Civi\Test as T;'));
    }

    public function testUnimportedTestClassIsNotTheCoreBuilder(): void
    {
        $this->assertSilent($this->check('return Test::headless();'));
    }

    public function testBuilderInsideAnArgumentListIsPartOfTheOuterChain(): void
    {
        $this->assertSilent($this->check('return ck_headless(fn() => ck_headless())->apply();'));
    }

    public function testCallAfterApplyStillCountsAsApplied(): void
    {
        $this->assertSilent($this->check('return ck_headless()->apply()->foo();'));
    }

    private function check(string $statement, string $use = ''): Reporter
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/PullTest.php' => "<?php $use\nclass PullTest {\n"
                . "  public function setUpHeadless() {\n"
                . "    // Installs the extension.\n"
                . "\n"
                . "    $statement\n"
                . "  }\n"
                . "}\n",
        ], git: true);

        return $this->run_(new HeadlessBuilderAppliedCheck(), $context);
    }
}
