<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ContainerServiceReferenceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ContainerServiceReferenceCheckTest extends CheckTestCase
{
    public function testSilentWithoutContainerCode(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => "<?php\nfunction greeter_civicrm_config(&\$config) {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    public function testAnExistingDefinitionIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container("new Definition('Civi\\Greeter\\Subscriber')"),
            'Civi/Greeter/Subscriber.php' => "<?php\nnamespace Civi\\Greeter;\nclass Subscriber {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    public function testAMissingDefinitionClassFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container("new Definition('Civi\\Greeter\\Gone')"),
            'Civi/Greeter/Subscriber.php' => "<?php\nnamespace Civi\\Greeter;\nclass Subscriber {}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new ContainerServiceReferenceCheck(), $context),
            'Civi/Greeter/Gone.php'
        );
    }

    public function testAMissingClassConstantDefinitionFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container('new Definition(\\Civi\\Greeter\\Gone::class)'),
        ], git: true);
        $this->assertFails($this->run_(new ContainerServiceReferenceCheck(), $context), 'Civi/Greeter/Gone.php');
    }

    public function testAMissingRegisterClassFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container("\$container->register('greeter.gone', 'CRM_Greeter_Gone')"),
        ], git: true);
        $this->assertFails($this->run_(new ContainerServiceReferenceCheck(), $context), 'CRM/Greeter/Gone.php');
    }

    public function testAMissingYamlServiceClassFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'services.yml' => "services:\n  greeter.gone:\n    class: Civi\\Greeter\\Gone\n",
        ], git: true);
        $this->assertFails($this->run_(new ContainerServiceReferenceCheck(), $context), 'Civi/Greeter/Gone.php');
    }

    public function testAForeignServiceClassIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container("new Definition('Civi\\Core\\SomeService')"),
            'services.yml' => "services:\n  other:\n    class: Symfony\\Component\\Foo\\Bar\n",
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    /** A dynamic class expression cannot be resolved and must not be guessed. */
    public function testADynamicClassNameIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'greeter.php' => $this->container('new Definition($className)'),
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    /** Test doubles are not shipped services. */
    public function testTestsAreNotScanned(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'tests/phpunit/FooTest.php' => $this->container("new Definition('Civi\\Greeter\\Gone')"),
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    private function container(string $expression): string
    {
        return "<?php\n\nfunction greeter_civicrm_container(\$container) {\n  \$definition = $expression;\n}\n";
    }

    public function testVendoredCodeUnderNodeModulesIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'node_modules/dep/container.php' => $this->container("new Definition('Civi\\Greeter\\Gone')"),
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    private function psr4Greeter(string $expression): \CiviKitchen\Ckconform\Context
    {
        return $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter', extra: '<classloader><psr4 prefix="Civi\\Greeter\\" path="src"/></classloader>'),
            'src/Subscriber.php' => "<?php\nnamespace Civi\\Greeter;\nclass Subscriber {}\n",
            'greeter.php' => $this->container($expression),
        ], git: true);
    }

    /** core's extension loader honours <classloader><psr4>, so src/ holds Civi\Greeter\. */
    public function testAClassUnderAPsr4ClassloaderPathIsFound(): void
    {
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $this->psr4Greeter("new Definition('Civi\\Greeter\\Subscriber')")));
    }

    public function testAMissingClassUnderAPsr4PathFails(): void
    {
        $this->assertFails(
            $this->run_(new ContainerServiceReferenceCheck(), $this->psr4Greeter("new Definition('Civi\\Greeter\\Gone')")),
            'src/Gone.php',
        );
    }

    /** civix's own classloader maps all of `Civi\`, core's classes included. */
    public function testACoreClassUnderTheCivixClassloaderIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter', extra: '<classloader><psr4 prefix="Civi\\" path="Civi"/></classloader>'),
            'greeter.php' => $this->container("new Definition(\\Civi\\Token\\TokenProcessor::class)"),
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }

    public function testAMissingOwnClassUnderTheCivixClassloaderFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter', extra: '<classloader><psr4 prefix="Civi\\" path="Civi"/></classloader>'),
            'greeter.php' => $this->container("new Definition('Civi\\Greeter\\Gone')"),
        ], git: true);
        $this->assertFails($this->run_(new ContainerServiceReferenceCheck(), $context), 'Civi/Greeter/Gone.php');
    }

    /** Composer tries every path of a prefix and every prefix that matches. */
    public function testAClassInASecondComposerPathIsFound(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'composer.json' => '{"autoload": {"psr-4": {"Civi\\\\Greeter\\\\": ["src", "lib"]}}}',
            'lib/Subscriber.php' => "<?php\nnamespace Civi\\Greeter;\nclass Subscriber {}\n",
            'greeter.php' => $this->container("new Definition('Civi\\Greeter\\Subscriber')"),
        ], git: true);
        $this->assertSilent($this->run_(new ContainerServiceReferenceCheck(), $context));
    }
}
