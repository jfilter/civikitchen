<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4ActionVarCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class Api4ActionVarCheckTest extends CheckTestCase
{
    private function action(string $body): string
    {
        return "<?php\nnamespace Civi\\Ext\\Api4\\Action;\n"
            . "use Civi\\Api4\\Generic\\AbstractAction;\n"
            . "class Send extends AbstractAction {\n" . $body . "\n}\n";
    }

    public function testSilentWithoutAnyActionClass(): void
    {
        $context = $this->repo(['Civi/Ext/Thing.php' => "<?php\nclass Thing {}\n"], git: true);
        $this->assertSilent($this->run_(new Api4ActionVarCheck(), $context));
    }

    /** The crash this rule exists for. */
    public function testAGenericInAnActionVarFails(): void
    {
        $context = $this->repo([
            'Civi/Ext/Api4/Action/Send.php' => $this->action(
                "  /**\n   * @var array<string, mixed>\n   */\n  protected array \$config = [];"
            ),
        ], git: true);
        $reporter = $this->run_(new Api4ActionVarCheck(), $context);
        $this->assertFails($reporter, 'Send.php::$config');
    }

    /** The house pattern: plain @var, shape in @phpstan-var. */
    public function testPlainVarWithPhpstanVarPasses(): void
    {
        $context = $this->repo([
            'Civi/Ext/Api4/Action/Send.php' => $this->action(
                "  /**\n   * @var array\n   * @phpstan-var array<string, mixed>\n   */\n  protected array \$config = [];"
            ),
        ], git: true);
        $this->assertPasses($this->run_(new Api4ActionVarCheck(), $context));
    }

    public function testScalarVarsAreFine(): void
    {
        $context = $this->repo([
            'Civi/Ext/Api4/Action/Send.php' => $this->action(
                "  /** @var string */\n  protected string \$channel = '';\n"
                . "  /** @var int */\n  protected int \$limit = 0;"
            ),
        ], git: true);
        $this->assertPasses($this->run_(new Api4ActionVarCheck(), $context));
    }

    public function testArrayVarWithoutPhpstanVarWarns(): void
    {
        $context = $this->repo([
            'Civi/Ext/Api4/Action/Send.php' => $this->action(
                "  /**\n   * @var array\n   */\n  protected array \$config = [];"
            ),
        ], git: true);
        $reporter = $this->run_(new Api4ActionVarCheck(), $context);
        $this->assertWarns($reporter, 'Send.php::$config');
        // The array-without-shape case is a warning, not a failure.
        self::assertSame(0, $reporter->failures());
    }

    /**
     * A `@var` docblock inside a method body must not be read as a property —
     * it lives at a deeper brace depth.
     */
    public function testAVarInsideAMethodBodyIsNotAProperty(): void
    {
        $context = $this->repo([
            'Civi/Ext/Api4/Action/Send.php' => $this->action(
                "  public function _run(\$result): void {\n"
                . "    /** @var array<int, string> \$rows */\n    \$rows = [];\n  }"
            ),
        ], git: true);
        $this->assertPasses($this->run_(new Api4ActionVarCheck(), $context));
    }

    /**
     * Test classes mirror the Api4/Action/ path but are not actions; a generic
     * in a test's own property must not be reported.
     */
    public function testTestClassesUnderTheMirroredPathAreIgnored(): void
    {
        $context = $this->repo([
            'tests/phpunit/Civi/Ext/Api4/Action/SendTest.php' => "<?php\nclass SendTest {\n"
                . "  /** @var array<string, mixed> */\n  protected array \$fixture = [];\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4ActionVarCheck(), $context));
    }
}
