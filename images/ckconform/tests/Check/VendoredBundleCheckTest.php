<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\VendoredBundleCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class VendoredBundleCheckTest extends CheckTestCase
{
    public function testFailsOnACommittedMinifiedJsBundle(): void
    {
        $context = $this->repo(['js/lib/charting.min.js' => '!function(){}();'], git: true);
        $this->assertFails(
            $this->run_(new VendoredBundleCheck(), $context),
            'vendored bundle committed: js/lib/charting.min.js — declare it in package.json and serve it from node_modules',
        );
    }

    public function testFailsOnACommittedMinifiedCssBundle(): void
    {
        $context = $this->repo(['css/theme.min.css' => 'body{margin:0}'], git: true);
        $this->assertFails(
            $this->run_(new VendoredBundleCheck(), $context),
            'vendored bundle committed: css/theme.min.css — declare it in package.json and serve it from node_modules',
        );
    }

    public function testABowerComponentsTreeIsFlaggedOnceNotPerFile(): void
    {
        $context = $this->repo([
            'bower_components/lib-a/dist/a.min.js' => '',
            'bower_components/lib-b/dist/b.js' => '',
        ], git: true);
        $reporter = $this->run_(new VendoredBundleCheck(), $context);
        self::assertSame(
            ['vendored bundle committed: bower_components — declare the libraries in package.json instead'],
            $reporter->messages('FAIL'),
        );
    }

    /**
     * A committed node_modules is CommittedArtifactCheck's finding; repeating
     * every minified file inside it here would bury that one finding.
     */
    public function testMinifiedFilesInsideNodeModulesAreNotDoubleReported(): void
    {
        $context = $this->repo(['node_modules/dep/dist/dep.min.js' => ''], git: true);
        $this->assertSilent($this->run_(new VendoredBundleCheck(), $context));
    }

    public function testBundlesPolicyAllowsCommittedBundles(): void
    {
        $context = $this->repo([
            'js/lib/charting.min.js' => '',
            '.ckconform' => "bundles=committed -- deploy path has no npm\n",
        ], git: true);
        $reporter = $this->run_(new VendoredBundleCheck(), $context);
        self::assertSame(0, $reporter->failures());
        self::assertSame(
            ['bundles committed — declared deliberate in .ckconform (committed -- deploy path has no npm)'],
            $reporter->messages('ok'),
        );
    }

    public function testPassesOnPlainOwnSources(): void
    {
        $context = $this->repo([
            'js/chartModel.js' => 'const model = {};',
            'package.json' => '{"dependencies":{"echarts":"5.5.0"}}',
        ], git: true);
        $this->assertSilent($this->run_(new VendoredBundleCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['js/lib/charting.min.js' => '']);
        $this->assertSilent($this->run_(new VendoredBundleCheck(), $context));
    }
}
