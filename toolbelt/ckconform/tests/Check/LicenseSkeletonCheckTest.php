<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\LicenseSkeletonCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class LicenseSkeletonCheckTest extends CheckTestCase
{
    public function testSilentWithoutALicenseFile(): void
    {
        $this->assertSilent($this->run_(new LicenseSkeletonCheck(), $this->repo([])));
    }

    public function testFailsWhenThePackageLineNamesAnotherExtension(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.mine'),
            'LICENSE.txt' => "Package: de.example.borrowed\nCopyright (C) 2026 Example Ltd\n",
        ]);
        $this->assertFails(
            $this->run_(new LicenseSkeletonCheck(), $context),
            "LICENSE.txt says 'Package: de.example.borrowed' but info.xml key is 'de.example.mine' (copied skeleton)"
        );
    }

    public function testSilentWhenThePackageLineMatchesTheKey(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.mine'),
            'LICENSE.txt' => "Package: de.example.mine\nCopyright (C) 2026 Example Ltd\n",
        ]);
        $this->assertSilent($this->run_(new LicenseSkeletonCheck(), $context));
    }

    public function testWarnsAboutAnUnfilledCopyrightHolder(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.mine'),
            'LICENSE.txt' => "Package: de.example.mine\nCopyright (C) 2026 FIXME\n",
        ]);
        $reporter = $this->run_(new LicenseSkeletonCheck(), $context);
        $this->assertWarns($reporter, 'LICENSE.txt still contains FIXME (copyright holder unfilled)');
        $this->assertPasses($reporter);
    }

    public function testALicenseFileWithoutAPackageLineIsNotASkeleton(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.mine'),
            'LICENSE.txt' => "All rights reserved.\n",
        ]);
        $this->assertSilent($this->run_(new LicenseSkeletonCheck(), $context));
    }

    /**
     * The key comes from the root element's attribute, not from the first
     * `key="…"` on a line mentioning `<extension`.
     */
    public function testTheKeyIsReadFromTheRootAttribute(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.mine', extra: '  <comments>see key="de.example.other"</comments>'),
            'LICENSE.txt' => "Package: de.example.mine\n",
        ]);
        $this->assertSilent($this->run_(new LicenseSkeletonCheck(), $context));
    }
}
