<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\VersionFormatCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class VersionFormatCheckTest extends CheckTestCase
{
    /** @return iterable<string, array{string}> */
    public static function releasable(): iterable
    {
        yield 'plain' => ['1.3.0'];
        yield 'zero major' => ['0.1.0'];
        yield 'pre-release' => ['1.3.0-alpha3'];
        yield 'dotted pre-release' => ['1.3.0-rc.1'];
        yield 'hyphen in pre-release' => ['2.0.0-x-y.0'];
    }

    /** @return iterable<string, array{string}> */
    public static function unreleasable(): iterable
    {
        yield 'four components' => ['2.2.7.1'];
        yield 'two components' => ['1.0'];
        yield 'leading zero' => ['1.03.0'];
        yield 'leading zero in numeric pre-release' => ['1.3.0-rc.01'];
        yield 'empty pre-release identifier' => ['1.2.3-rc..1'];
        yield 'build metadata' => ['1.3.0+build.5'];
        yield 'v prefix' => ['v1.3.0'];
    }

    /** @dataProvider releasable */
    public function testPassesOnAReleasableVersion(string $version): void
    {
        $this->assertPasses($this->run_(new VersionFormatCheck(), $this->versioned($version)));
    }

    /** @dataProvider unreleasable */
    public function testFailsOnAVersionNoReleaseCanCarry(string $version): void
    {
        $reporter = $this->run_(new VersionFormatCheck(), $this->versioned($version));
        $this->assertFails($reporter, "'{$version}' is not X.Y.Z");
        $this->assertFails($reporter, 'set <version> to a SemVer version such as 0.1.0 and release it');
    }

    public function testFailsWithoutAVersion(): void
    {
        $this->assertFails($this->run_(new VersionFormatCheck(), $this->repo([])), '<version> is missing');
    }

    private function versioned(string $version): \CiviKitchen\Ckconform\Context
    {
        return $this->repo(['info.xml' => $this->infoXml(extra: "  <version>{$version}</version>")]);
    }
}
