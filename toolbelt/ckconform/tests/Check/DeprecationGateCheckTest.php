<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\DeprecationGateCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class DeprecationGateCheckTest extends CheckTestCase
{
    private const GOOD_CONFIG = '<?xml version="1.0"?>'
        . '<phpunit convertDeprecationsToExceptions="true" bootstrap="tests/phpunit/bootstrap.php"/>';

    private const GOOD_BOOTSTRAP = "<?php\nerror_reporting(E_ALL);\n";

    public function testSaysNothingWithoutATestsPhpunitDirectory(): void
    {
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $this->repo([])));
    }

    /** PhpunitConfigCheck owns the missing-config finding; two voices on one file is noise. */
    public function testSaysNothingWithoutAPhpunitConfig(): void
    {
        $context = $this->repo(['tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testPassesWithBothIngredients(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testWarnsWhenTheAttributeIsMissing(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit bootstrap="tests/phpunit/bootstrap.php"/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns(
            $this->run_(new DeprecationGateCheck(), $context),
            'does not set convertDeprecationsToExceptions="true"',
        );
    }

    public function testWarnsWhenTheAttributeIsFalse(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit convertDeprecationsToExceptions="false"/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'convertDeprecationsToExceptions');
    }

    /**
     * A commented-out attribute is not an attribute — the predecessor grep for
     * the literal string would have read this file as gated.
     */
    public function testACommentedAttributeDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => "<?xml version=\"1.0\"?>\n"
                . "<!-- convertDeprecationsToExceptions=\"true\" -->\n<phpunit/>",
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'convertDeprecationsToExceptions');
    }

    /**
     * The attribute alone is not the gate: PHPUnit skips errors outside
     * error_reporting(), and the CLI default masks E_DEPRECATED.
     */
    public function testWarnsWhenTheBootstrapDoesNotWidenErrorReporting(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'error_reporting(E_ALL)');
    }

    public function testAnErrorReportingIniInTheConfigAlsoCounts(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit convertDeprecationsToExceptions="true">'
                . '<php><ini name="error_reporting" value="-1"/></php></phpunit>',
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** E_ALL minus notices still carries E_DEPRECATED — that is the ingredient. */
    public function testEAllMinusNoticesStillCounts(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\nerror_reporting(E_ALL & ~E_NOTICE);\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testBothIngredientsCanBeMissingAtOnce(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit/>',
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        self::assertCount(2, $this->run_(new DeprecationGateCheck(), $context)->messages('warn'));
    }

    /** An untracked local tests/phpunit is not a suite the repo ships. */
    public function testAnUntrackedSuiteDirectoryIsIgnored(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit/>',
        ], git: true);
        mkdir($context->root . '/tests/phpunit', 0777, true);
        file_put_contents($context->root . '/tests/phpunit/bootstrap.php', '<?php');
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** phpunit.xml is checked too — a repo may keep the non-dist form. */
    public function testThePlainPhpunitXmlIsChecked(): void
    {
        $context = $this->repo([
            'phpunit.xml' => '<?xml version="1.0"?><phpunit/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'phpunit.xml does not set');
    }
}
