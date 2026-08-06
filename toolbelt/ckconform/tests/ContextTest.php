<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

final class ContextTest extends CheckTestCase
{
    /**
     * Two callers used to parse <requires> separately; one dropped empty
     * elements, the other let '' through, where it could never match any real
     * key in an in_array() test. The shared parser trims and drops.
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

    public function testRequiredExtensionsIsEmptyWithoutInfoXmlOrRequires(): void
    {
        self::assertSame([], $this->repo([])->requiredExtensions());
        self::assertSame([], $this->repo(['info.xml' => 'not xml'])->requiredExtensions());
    }
}
