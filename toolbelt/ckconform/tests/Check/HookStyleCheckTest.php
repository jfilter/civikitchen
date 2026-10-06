<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HookStyleCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class HookStyleCheckTest extends CheckTestCase
{
    public function testSilentWithoutThePolicyKey(): void
    {
        // Which style a repo wants is the repo's business — no key, no opinion.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testFailsOnAnUnknownStyleValue(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=events\n",
            'fixture.php' => "<?php\n",
        ]);
        $this->expectException(\RuntimeException::class);
        $this->run_(new HookStyleCheck(), $context);
    }

    public function testWarnsOnAClassicBusinessHook(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>scan-classes@1.0.0</mixin></mixins>'),
            'fixture.php' => <<<'PHP'
                <?php
                function fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
                }
                PHP,
        ]);
        $reporter = $this->run_(new HookStyleCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'fixture_civicrm_post()');
        $this->assertWarns($reporter, 'scan classes');
    }

    public function testTheClassicOnlyRemainderStaysSilent(): void
    {
        // Pre-boot, lifecycle, the civix config stub and return-value hooks are
        // exactly what the style permits as functions.
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>scan-classes@1.0.0</mixin></mixins>'),
            'fixture.php' => <<<'PHP'
                <?php
                function fixture_civicrm_config(&$config) {
                }
                function fixture_civicrm_install() {
                }
                function fixture_civicrm_entityTypes(&$entityTypes) {
                }
                function fixture_civicrm_container($container) {
                }
                function fixture_civicrm_permission(&$permissions) {
                }
                function fixture_civicrm_check(&$messages) {
                }
                function fixture_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testAForeignPrefixIsNotAStyleFinding(): void
    {
        // HookDispatchNameCheck owns the wrong-prefix verdict; reporting it
        // here too would double every message.
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'fixture.php' => <<<'PHP'
                <?php
                function otherext_civicrm_post($op) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testWarnsWhenListenerClassesLackTheScanClassesMixin(): void
    {
        // The style's own failure mode: the class registers nothing.
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'Civi/Fixture/Listener.php' => <<<'PHP'
                <?php
                namespace Civi\Fixture;

                use Civi\Core\Service\AutoSubscriber;

                class Listener extends AutoSubscriber {
                  public static function getSubscribedEvents(): array {
                    return ['hook_civicrm_post' => 'onPost'];
                  }
                }
                PHP,
        ]);
        $reporter = $this->run_(new HookStyleCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'scan-classes mixin');
    }

    public function testListenerClassesWithTheMixinAreSilent(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>scan-classes@1.0.0</mixin></mixins>'),
            'Civi/Fixture/Listener.php' => <<<'PHP'
                <?php
                namespace Civi\Fixture;

                use Civi\Core\Service\AutoSubscriber;

                class Listener extends AutoSubscriber {
                  public static function getSubscribedEvents(): array {
                    return ['hook_civicrm_post' => 'onPost'];
                  }
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testACommentMentioningAutoSubscriberDoesNotCountAsAListenerClass(): void
    {
        // Token-judged: prose in a comment must not trigger the mixin warning.
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'fixture.php' => <<<'PHP'
                <?php
                // One day this should become an AutoSubscriber.
                function fixture_civicrm_config(&$config) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testAFullyQualifiedListenerBaseCounts(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'Civi/Fixture/Listener.php' => <<<'PHP'
                <?php
                namespace Civi\Fixture;

                class Listener extends \Civi\Core\Service\AutoSubscriber {
                  public static function getSubscribedEvents(): array {
                    return [];
                  }
                }
                PHP,
        ]);
        $this->assertWarns($this->run_(new HookStyleCheck(), $context), 'scan-classes mixin');
    }

    public function testAClassMerelyNamedLikeAListenerBaseDoesNotCount(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'Civi/Fixture/Factory.php' => <<<'PHP'
                <?php
                namespace Civi\Fixture;

                class Factory extends \Acme\AutoSubscriberFactory {
                  const NOTE = 'see \Civi\Core\Service\AutoSubscriber';
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testAClassicOnlyHookInAnotherCaseStaysSilent(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'fixture.php' => <<<'PHP'
                <?php
                function Fixture_civicrm_Install() {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testABusinessHookUnderAnotherCasePrefixWarns(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'fixture.php' => <<<'PHP'
                <?php
                function Fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
                }
                PHP,
        ]);
        $this->assertWarns($this->run_(new HookStyleCheck(), $context), 'Fixture_civicrm_post()');
    }

    /** A method called hookInterface() is not the HookInterface base. */
    public function testAMethodNamedLikeAListenerBaseDoesNotCount(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'Civi/Fixture/Registry.php' => "<?php\nnamespace Civi\\Fixture;\n\nclass Registry {\n"
                . "  public function hookInterface(): string { return ''; }\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new HookStyleCheck(), $context));
    }

    public function testAnImportedListenerInterfaceCounts(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "hook_style=listener\n",
            'Civi/Fixture/Listener.php' => "<?php\nnamespace Civi\\Fixture;\n\nuse Civi\\Core\\HookInterface;\n\n"
                . "class Listener implements HookInterface {}\n",
        ], git: true);
        $this->assertWarns($this->run_(new HookStyleCheck(), $context), 'scan-classes');
    }
}
