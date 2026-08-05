<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\DeclaredCallbackCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class DeclaredCallbackCheckTest extends CheckTestCase
{
    public function testSilentWithoutMenuXml(): void
    {
        $context = $this->repo(['CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {}\n"], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testAnExistingOwnCallbackIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => $this->menu('CRM_Greeter_Page_Foo'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testAMissingOwnCallbackFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => $this->menu('CRM_Greeter_Page_Gone'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new DeclaredCallbackCheck(), $context),
            'CRM/Greeter/Page/Gone.php'
        );
    }

    public function testAForeignCallbackIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => $this->menu('CRM_Contact_Page_View'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testAClassMethodCallbackWithThatMethodIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => $this->menu('CRM_Greeter_Page_Foo::render'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {\n  public function render() {}\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testAClassMethodCallbackWithoutThatMethodFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => $this->menu('CRM_Greeter_Page_Foo::render'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo {\n  public function run() {}\n}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new DeclaredCallbackCheck(), $context),
            'declares no function render()'
        );
    }

    /** A container entry carries a label and a path, no callback. */
    public function testAnItemWithoutCallbackIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => "<?xml version=\"1.0\"?>\n<menu>\n  <item>\n    <path>civicrm/greeter</path>\n    <title>Greeter</title>\n  </item>\n</menu>\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testAccessCallbackIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => "<?xml version=\"1.0\"?>\n<menu>\n  <item>\n    <path>civicrm/greeter</path>\n    <access_callback>CRM_Greeter_Page_Gone::check</access_callback>\n    <page_arguments>reset=1</page_arguments>\n  </item>\n</menu>\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeclaredCallbackCheck(), $context));
    }

    public function testUnparseableMenuFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'xml/Menu/greeter.xml' => "<menu><item><path>x</path>\n",
        ], git: true);
        $this->assertFails($this->run_(new DeclaredCallbackCheck(), $context), 'unparseable');
    }

    private function menu(string $callback): string
    {
        return <<<XML
            <?xml version="1.0"?>
            <menu>
              <item>
                <path>civicrm/greeter</path>
                <page_callback>{$callback}</page_callback>
              </item>
            </menu>
            XML;
    }
}
