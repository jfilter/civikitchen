<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HookDispatchNameCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class HookDispatchNameCheckTest extends CheckTestCase
{
    public function testSilentWithoutPhpFiles(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testPassesOnCorrectPrefixAndKnownHook(): void
    {
        // The fixture info.xml uses key/file 'fixture'.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_config(&$config) {
            }

            function fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testFailsOnForeignPrefix(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function otherext_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertFails($reporter, 'otherext_civicrm_post() will never fire');
        $this->assertFails($reporter, "fixture_civicrm_post()");
    }

    public function testFailsOnForeignPrefixInNestedFile(): void
    {
        $context = $this->repo(['CRM/Fixture/Extras.php' => <<<'PHP'
            <?php
            function legacyext_civicrm_buildForm($formName, &$form) {
            }
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            'CRM/Fixture/Extras.php',
        );
    }

    public function testWarnsOnUnknownHookSuffix(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_postCommmit($op, $objectName, $objectId, $objectRef) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "unknown hook suffix 'postCommmit'");
    }

    public function testCivixGlueIsExempt(): void
    {
        // civix generates dispatch glue under its own naming; renaming it is not
        // the extension author's job.
        $context = $this->repo(['fixture.civix.php' => <<<'PHP'
            <?php
            function _fixturehelper_civicrm_setThing() {
            }

            function civixgenerated_civicrm_managed(&$entities) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testClassMethodsAreExempt(): void
    {
        // A method named like a hook is not a hook — depth tracking must not
        // mistake it for a global function.
        $context = $this->repo(['CRM/Fixture/Hooks.php' => <<<'PHP'
            <?php
            class CRM_Fixture_Hooks {
              public function otherext_civicrm_post($op) {
                $label = "brace {$op} inside a string";
                $fn = function ($x) { return $x; };
                return $label . $fn($op);
              }
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testUnderscorePrefixedHelpersAreExempt(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function _otherext_civicrm_helper() {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testTestDirectoryIsExempt(): void
    {
        $context = $this->repo(['tests/phpunit/FakeHookTest.php' => <<<'PHP'
            <?php
            function otherext_civicrm_post($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testMainFileOnDiskWinsOverDriftedInfoXml(): void
    {
        // info.xml says 'stale', but foo.php + foo.civix.php are what CiviCRM
        // loads, so hooks named foo_* do fire and must not be reported.
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'stale'),
            'foo.php' => <<<'PHP'
                <?php
                function foo_civicrm_config(&$config) {
                }
                PHP,
            'foo.civix.php' => "<?php\n",
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testUsesTrackedFilesOnlyInAGitRepo(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\n"], git: true);
        file_put_contents($context->path('untracked.php'), <<<'PHP'
            <?php
            function otherext_civicrm_post($op) {
            }
            PHP);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testPolicyDeclaredHookSuffixIsSilent(): void
    {
        $context = $this->repo([
            '.ckconform' => "known_hooks=acmeConnectors,otherHook\n",
            'fixture.php' => <<<'PHP'
                <?php
                function fixture_civicrm_acmeConnectors(&$connectors) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }
}
