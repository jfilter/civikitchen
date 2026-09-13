<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PermissionClosureCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PermissionClosureCheckTest extends CheckTestCase
{
    private const HOOK = <<<'PHP'
        <?php
        function myext_civicrm_permission(&$permissions) {
          $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
          $permissions['access MyExt reports'] = ['label' => 'access MyExt reports'];
        }
        PHP;

    public function testSaysNothingWhenEveryUsedPermissionIsCoreOrDefined(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM;administer MyExt'),
            'Civi/Api4/Action/Thing.php' => "<?php\nCRM_Core_Permission::check('access MyExt reports');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testPseudoPermissionsAreAccepted(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\nreturn [['permission' => ['*always allow*']]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAlwaysDenyIsAccepted(): void
    {
        $context = $this->repo([
            'schema/Thing.entityType.php' => "<?php\nreturn ['getFields' => fn() => ['secret' => ['permission' => ['*always deny*']]]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * An entity with a column called `permission` defines that column with an
     * associative array; its title, SQL type and input type are not permissions.
     */
    public function testAFieldNamedPermissionIsNotAPermissionSpec(): void
    {
        $context = $this->repo([
            'schema/Denial.entityType.php' => <<<'PHP'
                <?php
                return [
                  'getFields' => fn() => [
                    'permission' => [
                      'title' => 'Permission',
                      'sql_type' => 'varchar(255)',
                      'input_type' => 'Text',
                      'required' => TRUE,
                    ],
                    'legacy' => array('permission' => array('title' => 'Legacy permission')),
                    'secret' => [
                      'title' => 'Secret',
                      'permission' => ['administer SomeOtherExtension'],
                    ],
                  ],
                ];
                PHP,
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([$this->unknown('schema/Denial.entityType.php')], $reporter->messages('warn'));
    }

    public function testAnUnclosedPermissionListYieldsNothing(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\n\$x = ['permission' => ['administer SomeOtherExtension'\n\$z = 'tail string';\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAnAttributeOrClosureInsideASpecDoesNotEndIt(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return ['permission' => [#[A] function () { return 'noise string'; }, 'administer SomeOtherExtension']];
                PHP,
        ], git: true);
        self::assertSame(
            [$this->unknown('managed/Thing.mgd.php')],
            $this->run_(new PermissionClosureCheck(), $context)->messages('warn'),
        );
    }

    public function testEscapesInPermissionLiteralsFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return [
                  ['permission' => 'generate any user\'s JWT'],
                  ['permission' => ["validate any user\x27s credentials"]],
                ];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testArraySubscriptsInsideASpecAreNotPermissions(): void
    {
        $context = $this->repo([
            'Civi/Api4/Getter.php' => <<<'PHP'
                <?php
                $record = ['permission' => [$reportInstance['permission']]];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCallArgumentsInsideASpecAreNotPermissions(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return ['permission' => array(array('administer SomeOtherExtension'), E::ts('not a permission'))];
                PHP,
        ], git: true);
        self::assertSame(
            [$this->unknown('managed/Thing.mgd.php')],
            $this->run_(new PermissionClosureCheck(), $context)->messages('warn'),
        );
    }

    /**
     * Declared by core's afform extension through hook_civicrm_permission and
     * hook_civicrm_permissionList.
     */
    public function testAfformPermissionsAreKnown(): void
    {
        $context = $this->repo([
            'ang/afformThing.aff.php' => "<?php\nreturn ['permission' => ['@afformPageToken', 'manage own afform']];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * Core writes OR groups as nested lists; every leaf counts, including
     * those after the first inner list closes.
     */
    public function testEveryLeafOfANestedPermissionListIsRead(): void
    {
        $context = $this->repo([
            'schema/Contact.entityType.php' => <<<'PHP'
                <?php
                return ['getFields' => fn() => ['api_key' => [
                  'permission' => [['administer CiviCRM', 'edit api keys'], ['administer SomeOtherExtension']],
                ]]];
                PHP,
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'administer SomeOtherExtension'");
    }

    public function testEscapesInACheckLiteralFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'CRM/Myext/Page/Thing.php' => "<?php\nif (CRM_Core_Permission::check('generate any user\\'s JWT')) {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testEscapesInAHookAssignmentFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions['edit my client\'s tokens'] = ['label' => 'Tokens'];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu("edit my client's tokens"),
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testEscapesInAReturnedPermissionMapFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions += ['edit my client\'s notes' => ['label' => 'Notes']];
                }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check(\"edit my client's notes\");\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCheckCallsMatchClassAndMethodCaseInsensitively(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'CRM/Myext/Page/Lower.php' => "<?php\ncrm_core_permission::Check('acces MyExt reports');\n",
            'CRM/Myext/Page/Upper.php' => "<?php\n\\CRM_Core_Permission::CHECK('administer MyExtt');\n",
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertFails($reporter, "CRM/Myext/Page/Lower.php: permission 'acces MyExt reports'");
        $this->assertFails($reporter, "CRM/Myext/Page/Upper.php: permission 'administer MyExtt'");
    }

    public function testAClosingBraceInAStringDoesNotEndTheHookBody(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $note = 'closing } brace';
                  $permissions += ['administer My Ext' => ['label' => 'My Ext']];
                }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check('administer My Ext');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAnOpeningBraceInAStringDoesNotExtendTheHookBody(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $note = 'opening { brace';
                  $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
                }
                function myext_civicrm_config(&$config) {
                  $map = ['some unrelated label' => 1];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu('some unrelated labell'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'some unrelated labell'");
    }

    /**
     * The failure that started this check: a typo turns the guard into an
     * always-no and nothing at runtime says so.
     */
    public function testFailsForATypoOfAnOwnPermissionInMenuXml(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('administer MyEXt'),
        ], git: true);
        $this->assertFails(
            $this->run_(new PermissionClosureCheck(), $context),
            "xml/Menu/myext.xml: permission 'administer MyEXt' is never defined, but this extension defines 'administer MyExt'",
        );
    }

    public function testFailsForANearMissInAPhpPermissionCheck(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'CRM/Myext/Page/Thing.php' => "<?php\nif (CRM_Core_Permission::check('acces MyExt reports')) {}\n",
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "'acces MyExt reports'");
    }

    public function testFailsForANearMissInAnAffJson(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'ang/afsearchThing.aff.json' => '{"permission": ["administer MyExtt"]}',
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), 'afsearchThing.aff.json');
    }

    public function testWarnsForAWhollyUnknownPermissionBecauseItMayComeFromADependency(): void
    {
        $context = $this->repo([
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM;administer SomeOtherExtension'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'administer SomeOtherExtension' is neither a known core permission");
    }

    public function testDefinedButUnusedPermissionsAreNotReported(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testSemicolonAndCommaListsAreBothSplit(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM,administer MyExt;view all contacts'),
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCommaSeparatedPhpPermissionExpressionIsSplit(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return [['permission' => 'administer MyExt,view all contacts']];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testNonLiteralPermissionsAreIgnored(): void
    {
        $context = $this->repo([
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check(\$this->permission);\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * Unrelated strings elsewhere in a file that happens to hold the hook must
     * not enter the definition set — an over-wide set turns warnings into false
     * FAILs.
     */
    public function testOnlyTheHookBodyContributesDefinitions(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
                }
                function myext_civicrm_config(&$config) {
                  $map = ['some unrelated label' => 1];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu('some unrelated labell'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter);
    }

    public function testUntrackedFilesDoNotDecideTheVerdict(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK], git: true);
        file_put_contents($context->path('scratch.php'), "<?php\nCRM_Core_Permission::check('administer MyExtt');\n");
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    private function unknown(string $file): string
    {
        return "$file: permission 'administer SomeOtherExtension' is neither a known core permission "
            . 'nor defined by this extension — fine if a dependency defines it, a silent always-no otherwise';
    }

    private function menu(string $arguments): string
    {
        return <<<XML
            <?xml version="1.0"?>
            <menu>
              <item>
                <path>civicrm/myext/thing</path>
                <access_arguments>{$arguments}</access_arguments>
              </item>
            </menu>
            XML;
    }
}
