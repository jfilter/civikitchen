<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

final class ContextTest extends CheckTestCase
{
    /**
     * An empty <ext> element can never match a real key in an in_array() test;
     * the shared parser trims and drops it.
     */
    public function testRequiredExtensionsTrimsAndDropsEmptyElements(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: <<<'XML'
                  <requires>
                    <ext>  org.civicrm.search_kit  </ext>
                    <ext version="3.32">org.civicoop.civirules</ext>
                    <ext></ext>
                    <ext>   </ext>
                  </requires>
                XML),
        ]);
        self::assertSame(
            ['org.civicrm.search_kit', 'org.civicoop.civirules'],
            $context->requiredExtensions(),
        );
    }

    /** git trusts safe.directory only for the repository top level, not a monorepo extension below it. */
    public function testIsIgnoredInAMonorepoExtensionUnderAForeignOwner(): void
    {
        $context = $this->monorepoExtension(['.gitignore' => "*.log\n"], []);
        putenv('GIT_TEST_ASSUME_DIFFERENT_OWNER=1');
        try {
            self::assertTrue($context->isIgnored('debug.log'));
            self::assertFalse($context->isIgnored('info.xml'));
        } finally {
            putenv('GIT_TEST_ASSUME_DIFFERENT_OWNER');
        }
    }

    public function testRequiredExtensionsIsEmptyWithoutInfoXmlOrRequires(): void
    {
        self::assertSame([], $this->repo([])->requiredExtensions());
        self::assertSame([], $this->repo(['info.xml' => 'not xml'])->requiredExtensions());
    }
}
