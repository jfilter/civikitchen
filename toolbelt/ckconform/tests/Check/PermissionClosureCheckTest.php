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
        self::assertSame(
            ["schema/Denial.entityType.php: permission 'administer SomeOtherExtension' is neither a known core permission "
                . 'nor defined by this extension — fine if a dependency defines it, a silent always-no otherwise'],
            $reporter->messages('warn'),
        );
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
