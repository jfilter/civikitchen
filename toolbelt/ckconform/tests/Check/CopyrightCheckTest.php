<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CopyrightCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CopyrightCheckTest extends CheckTestCase
{
    public function testFailsWhenTheHolderIsMissingFromTheLicenseFile(): void
    {
        $context = $this->repo([
            '.ckconform' => "copyright=Example Ltd\n",
            'LICENSE.txt' => "Copyright (C) 2026 Somebody Else\n",
        ]);
        $this->assertFails(
            $this->run_(new CopyrightCheck(), $context),
            "LICENSE.txt does not name the copyright holder 'Example Ltd' from .ckconform"
        );
    }

    public function testSilentWhenTheHolderAppears(): void
    {
        $context = $this->repo([
            '.ckconform' => "copyright=Example Ltd\n",
            'LICENSE.txt' => "Copyright (C) 2026 Example Ltd. All rights reserved.\n",
        ]);
        $this->assertSilent($this->run_(new CopyrightCheck(), $context));
    }

    public function testSilentWithoutAPolicy(): void
    {
        $context = $this->repo([
            'LICENSE.txt' => "Copyright (C) 2026 Somebody Else\n",
        ]);
        $this->assertSilent($this->run_(new CopyrightCheck(), $context));
    }

    public function testSilentWithoutALicenseFile(): void
    {
        $context = $this->repo(['.ckconform' => "copyright=Example Ltd\n"]);
        $this->assertSilent($this->run_(new CopyrightCheck(), $context));
    }

    /** grep -F: a legal name is matched literally, case and all. */
    public function testTheMatchIsCaseSensitive(): void
    {
        $context = $this->repo([
            '.ckconform' => "copyright=Example Ltd\n",
            'LICENSE.txt' => "Copyright (C) 2026 EXAMPLE LTD\n",
        ]);
        $this->assertFails($this->run_(new CopyrightCheck(), $context));
    }
}
