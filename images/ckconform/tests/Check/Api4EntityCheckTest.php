<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4EntityCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

/**
 * The regression this check exists for: an api3->v4 migration moved two pages
 * onto \Civi\Api4\MailingAB, which core exposes only @since 6.17 while the
 * extension declared 6.10. Nothing caught it — the pages had no test.
 */
final class Api4EntityCheckTest extends CheckTestCase
{
    private ?string $core = null;

    protected function tearDown(): void
    {
        if ($this->core !== null && is_dir($this->core)) {
            exec('rm -rf ' . escapeshellarg($this->core));
        }
        $this->core = null;
        parent::tearDown();
    }

    protected function coreDir(): ?string
    {
        return $this->core;
    }

    /**
     * @param array<string, string|null> $entities Entity name => @since, or null
     *                                             for a class without the tag.
     * @param array<string, string|null> $bundled  Same, but under ext/<name>/.
     */
    private function core(array $entities, array $bundled = []): void
    {
        $this->core = sys_get_temp_dir() . '/ckconform-core-' . bin2hex(random_bytes(6));
        mkdir($this->core . '/Civi/Api4', 0777, true);
        foreach ($entities as $name => $since) {
            file_put_contents($this->core . '/Civi/Api4/' . $name . '.php', $this->entitySource($name, $since));
        }
        foreach ($bundled as $name => $since) {
            $this->bundle('civi_mail', $name, $since, 'component');
        }
    }

    private function entitySource(string $name, ?string $since): string
    {
        $tag = $since === null ? '' : "\n * @since {$since}";
        return "<?php\nnamespace Civi\\Api4;\n\n/**\n * {$name}.{$tag}\n */\nclass {$name} extends Generic\\DAOEntity {}\n";
    }

    public function testSilentWithoutACoreOnDisk(): void
    {
        $context = $this->repo(['CRM/X.php' => '<?php $x = \Civi\Api4\Nonsense::get();'], git: true);
        $this->assertSilent($this->run_(new Api4EntityCheck(), $context));
    }

    public function testFailsOnAnEntityCoreNeverShipped(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo(['CRM/X.php' => '<?php $x = \Civi\Api4\Nonsense::get();'], git: true);
        $this->assertFails(
            $this->run_(new Api4EntityCheck(), $context),
            'APIv4 entities referenced but not found in core or this extension: Nonsense'
        );
    }

    public function testPassesOnAnEntityCoreShips(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo(['CRM/X.php' => '<?php $x = \Civi\Api4\Contact::get();'], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    /**
     * The MailingAB case, exactly: the entity exists in the core we happen to run
     * against, and would still fatal on every site the extension supports.
     */
    public function testFailsWhenTheEntityIsNewerThanTheDeclaredCompatibility(): void
    {
        $this->core([], ['MailingAB' => '6.17']);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.10'),
            'CRM/X.php' => '<?php $x = \Civi\Api4\MailingAB::get();',
        ], git: true);

        $reporter = $this->run_(new Api4EntityCheck(), $context);
        $this->assertFails($reporter, 'APIv4 entities newer than the declared <ver>6.10</ver>: MailingAB(@since 6.17)');
        // The entity does exist, so the existence line must still say so.
        self::assertContains('every referenced APIv4 entity exists', $reporter->messages('ok'));
    }

    public function testPassesOnceTheDeclaredCompatibilityCatchesUp(): void
    {
        $this->core([], ['MailingAB' => '6.17']);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.17'),
            'CRM/X.php' => '<?php $x = \Civi\Api4\MailingAB::get();',
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    public function testAnEntityTheExtensionDefinesItselfIsItsOwnBusiness(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo([
            'Civi/Api4/EmailBuilderContent.php' => "<?php\nnamespace Civi\\Api4;\nclass EmailBuilderContent {}",
            'CRM/X.php' => '<?php $x = \Civi\Api4\EmailBuilderContent::get();',
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    public function testGenericAndActionAreNotEntities(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo([
            'CRM/X.php' => '<?php class Foo extends \Civi\Api4\Generic\DAOEntity { const A = \Civi\Api4\Action\Foo::class; }',
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    /**
     * The first cut parsed @since with sed's \+, unsupported by BSD sed, which
     * silently returned nothing — so the version check passed on everything. A
     * class with no @since must be treated as "cannot tell", never as "too new",
     * but a class WITH one must actually be read.
     */
    public function testAnEntityWithoutASinceTagIsNotFlagged(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.10'),
            'CRM/X.php' => '<?php $x = \Civi\Api4\Contact::get();',
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    public function testTheSinceTagIsActuallyRead(): void
    {
        $this->core(['Widget' => '9.1']);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.10'),
            'CRM/X.php' => '<?php $x = \Civi\Api4\Widget::get();',
        ], git: true);
        $this->assertFails($this->run_(new Api4EntityCheck(), $context), 'Widget(@since 9.1)');
    }

    public function testCivixShimAndDaoFilesAreIgnored(): void
    {
        $this->core(['Contact' => null]);
        $context = $this->repo([
            'fixture.civix.php' => '<?php $x = \Civi\Api4\Nonsense::get();',
            'CRM/Fixture/DAO/Thing.php' => '<?php $x = \Civi\Api4\AlsoNonsense::get();',
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    /**
     * A stub file or a docblock may name the class in prose. Without the leading
     * backslash it is not a reference to the entity at all — inside a namespaced
     * file it would resolve relative to that namespace. emailbuilder carries
     * exactly such a mention in a phpstan stub, and treating it as a reference
     * produced a false positive on an extension that no longer uses the entity.
     */
    public function testProseWithoutTheLeadingBackslashIsNotAReference(): void
    {
        $this->core([], ['MailingAB' => '6.17']);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.10'),
            'CRM/X.php' => "<?php\n/** See Civi\\Api4\\MailingAB for details. */\n",
        ], git: true);
        $this->assertPasses($this->run_(new Api4EntityCheck(), $context));
    }

    public function testAnImportCountsAsAReference(): void
    {
        $this->core([], ['MailingAB' => '6.17']);
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.10'),
            'CRM/X.php' => "<?php\nuse Civi\\Api4\\MailingAB;\n\$x = MailingAB::get();\n",
        ], git: true);
        $this->assertFails($this->run_(new Api4EntityCheck(), $context), 'MailingAB(@since 6.17)');
    }

    /**
     * An entity shipped by a core-bundled extension. $tag is the info.xml tag
     * that marks core plumbing (mgmt:required / component); pass null for a
     * genuinely optional extension like riverlea.
     */
    private function bundle(string $ext, string $entity, ?string $since, ?string $tag): void
    {
        $dir = $this->core . '/ext/' . $ext;
        if (!is_dir($dir . '/Civi/Api4')) {
            mkdir($dir . '/Civi/Api4', 0777, true);
        }
        file_put_contents($dir . '/Civi/Api4/' . $entity . '.php', $this->entitySource($entity, $since));
        $tags = $tag === null ? '' : "<tags><tag>{$tag}</tag></tags>";
        file_put_contents(
            $dir . '/info.xml',
            '<?xml version="1.0"?><extension key="' . $ext . '" type="module">' . $tags . '</extension>'
        );
    }

    public function testWarnsWhenAnOptionalProvidingExtensionIsNotRequired(): void
    {
        $this->core(['Contact' => null]);
        $this->bundle('riverlea', 'RiverleaStream', null, null);
        $context = $this->repo(['CRM/X.php' => '<?php $x = \Civi\Api4\RiverleaStream::get();'], git: true);

        $reporter = $this->run_(new Api4EntityCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'info.xml does not <requires> riverlea');
    }

    public function testSilentOnceTheProvidingExtensionIsRequired(): void
    {
        $this->core(['Contact' => null]);
        $this->bundle('riverlea', 'RiverleaStream', null, null);
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<requires><ext>riverlea</ext></requires>'),
            'CRM/X.php' => '<?php $x = \Civi\Api4\RiverleaStream::get();',
        ], git: true);

        $reporter = $this->run_(new Api4EntityCheck(), $context);
        self::assertSame([], $reporter->messages('warn'), $reporter->render());
    }

    /**
     * civi_mail, search_kit and flexmailer are core plumbing: no core extension
     * declares them, so demanding it would be noise nobody reads.
     */
    public function testCorePlumbingIsNotDemanded(): void
    {
        $this->core(['Contact' => null]);
        $this->bundle('civi_mail', 'Mailing', null, 'component');
        $this->bundle('search_kit', 'SearchDisplay', null, 'mgmt:required');
        $context = $this->repo([
            'CRM/X.php' => '<?php $a = \Civi\Api4\Mailing::get(); $b = \Civi\Api4\SearchDisplay::get();',
        ], git: true);

        $reporter = $this->run_(new Api4EntityCheck(), $context);
        self::assertSame([], $reporter->messages('warn'), $reporter->render());
    }
}
