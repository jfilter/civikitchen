<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MixinVersionCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MixinVersionCheckTest extends CheckTestCase
{
    public function testWarnsOnTheSmartyV2Alias(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>smarty-v2@1.0.1</mixin></mixins>'),
        ]);
        $reporter = $this->run_(new MixinVersionCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'deprecated alias');
    }

    public function testModernMixinsAreSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                extra: '<mixins><mixin>smarty@1.0.0</mixin><mixin>mgd-php@2.0.0</mixin></mixins>'
            ),
            'managed/A.mgd.php' => "<?php\nreturn [];\n",
        ]);
        $this->assertSilent($this->run_(new MixinVersionCheck(), $context));
    }

    public function testAdvisesMgdPhp2WhenAllFilesLieInItsPaths(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>mgd-php@1.0.0</mixin></mixins>'),
            'managed/A.mgd.php' => "<?php\nreturn [];\n",
            'root.mgd.php' => "<?php\nreturn [];\n",
        ]);
        $reporter = $this->run_(new MixinVersionCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'mgd-php@2 scans only the conventional homes');
    }

    public function testStaysSilentWhenAnMgdFileLivesOutsideTheV2Paths(): void
    {
        // Switching would silently drop this record — no advice without proof.
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<mixins><mixin>mgd-php@1.0.0</mixin></mixins>'),
            'resources/Odd.mgd.php' => "<?php\nreturn [];\n",
        ]);
        $this->assertSilent($this->run_(new MixinVersionCheck(), $context));
    }
}
