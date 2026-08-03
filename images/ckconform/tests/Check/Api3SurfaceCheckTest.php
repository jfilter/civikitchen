<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api3SurfaceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class Api3SurfaceCheckTest extends CheckTestCase
{
    public function testWarnsOnAnApi3ExportFunction(): void
    {
        $context = $this->repo(['api/v3/Widget/Get.php' => <<<'PHP'
            <?php
            function civicrm_api3_widget_get($params) {
              return civicrm_api3_create_success([]);
            }
            PHP,
        ]);
        $reporter = $this->run_(new Api3SurfaceCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'civicrm_api3_widget_get() ships an APIv3 endpoint');
    }

    public function testAnMgdFileUnderApiV3IsSilent(): void
    {
        // The directory alone proves nothing — even core's mgd-php@2 example
        // ships a *.mgd.php under api/v3/.
        $context = $this->repo(['api/v3/Group.mgd.php' => <<<'PHP'
            <?php
            return [];
            PHP,
        ]);
        $this->assertSilent($this->run_(new Api3SurfaceCheck(), $context));
    }

    public function testCallingApi3IsNotShippingApi3(): void
    {
        // Consumption is NoLegacyCallSniff's finding; this check judges the surface.
        $context = $this->repo(['CRM/Fixture/Caller.php' => <<<'PHP'
            <?php
            function fixture_helper() {
              return civicrm_api3('Contact', 'get', []);
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new Api3SurfaceCheck(), $context));
    }

    public function testAnIgnoreWithReasonSilences(): void
    {
        $context = $this->repo(['api/v3/Widget/Get.php' => <<<'PHP'
            <?php
            // ckconform-ignore api3-surface -- external partner integration pinned to v3 until 2027
            function civicrm_api3_widget_get($params) {
              return [];
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new Api3SurfaceCheck(), $context));
    }
}
