<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\NpmLicenseCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class NpmLicenseCheckTest extends CheckTestCase
{
    public function testSilentWithoutAPolicy(): void
    {
        $context = $this->repo(['package.json' => '{"license": "ISC"}'], git: true);
        $this->assertSilent($this->run_(new NpmLicenseCheck(), $context));
    }

    public function testFailsWhenAManifestDeclaresTheWrongLicence(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"name": "fixture", "license": "ISC"}',
        ], git: true);
        $this->assertFails(
            $this->run_(new NpmLicenseCheck(), $context),
            "package.json license is 'ISC', .ckconform expects 'UNLICENSED'"
        );
    }

    public function testAMissingLicenceFieldReportsAsUnset(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"name": "fixture"}',
        ], git: true);
        $this->assertFails(
            $this->run_(new NpmLicenseCheck(), $context),
            "package.json license is 'unset', .ckconform expects 'UNLICENSED'"
        );
    }

    public function testSilentWhenEveryTrackedManifestMatches(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"license": "UNLICENSED"}',
            'js/build/package.json' => '{"license": "unlicensed"}',
        ], git: true);
        $this->assertSilent($this->run_(new NpmLicenseCheck(), $context));
    }

    public function testNestedManifestsAreCheckedToo(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"license": "UNLICENSED"}',
            'js/build/package.json' => '{"license": "MIT"}',
        ], git: true);
        $this->assertFails(
            $this->run_(new NpmLicenseCheck(), $context),
            "js/build/package.json license is 'MIT', .ckconform expects 'UNLICENSED'"
        );
    }

    public function testVendoredManifestsUnderNodeModulesAreIgnored(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"license": "UNLICENSED"}',
            'node_modules/left-pad/package.json' => '{"license": "WTFPL"}',
        ], git: true);
        $this->assertSilent($this->run_(new NpmLicenseCheck(), $context));
    }

    /** Untracked manifests cannot be published by anyone else, so they are out. */
    public function testUntrackedManifestsAreIgnored(): void
    {
        $context = $this->repo([
            '.ckconform' => "npm_license=UNLICENSED\n",
            'package.json' => '{"license": "UNLICENSED"}',
        ], git: true);
        file_put_contents($context->path('later.package.json'), '{"license": "MIT"}');
        $this->assertSilent($this->run_(new NpmLicenseCheck(), $context));
    }
}
