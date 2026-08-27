<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Check\PolicyKeyCheck;
use CiviKitchen\Ckconform\Policy;

/**
 * The organisation-wide defaults layer: CK_DEFAULT_POLICY names a file in
 * the .ckconform format whose keys apply unless the repo sets them itself.
 */
final class PolicyLayerTest extends CheckTestCase
{
    private ?string $defaultsFile = null;

    protected function tearDown(): void
    {
        putenv(Policy::DEFAULTS_ENV);
        if ($this->defaultsFile !== null && is_file($this->defaultsFile)) {
            unlink($this->defaultsFile);
        }
        parent::tearDown();
    }

    private function defaults(string $contents): void
    {
        $this->defaultsFile = sys_get_temp_dir() . '/ckconform-defaults-' . bin2hex(random_bytes(6));
        file_put_contents($this->defaultsFile, $contents);
        putenv(Policy::DEFAULTS_ENV . '=' . $this->defaultsFile);
    }

    public function testRepoKeysReplaceDefaultsPerKey(): void
    {
        $merged = Policy::layered(
            "license=MIT\nvendored_paths=js/own.js -- repo copy\n",
            "license=Proprietary\ncopyright=Example Ltd\nvendored_paths=js/a.js -- fleet\nvendored_paths=js/b.js -- fleet\n",
        );
        self::assertSame(['MIT'], $merged['license']);
        self::assertSame(['Example Ltd'], $merged['copyright']);
        // A repeatable key set by the repo replaces the default's lines, it
        // does not append to them.
        self::assertSame(['js/own.js -- repo copy'], $merged['vendored_paths']);
    }

    public function testWithoutTheVariableOnlyTheRepoFileCounts(): void
    {
        putenv(Policy::DEFAULTS_ENV);
        self::assertNull(Policy::defaultsRaw());
        self::assertSame(['license' => ['MIT']], Policy::effective("license=MIT\n"));
    }

    public function testContextAndShellViewSeeTheMergedPolicy(): void
    {
        $this->defaults("license=Proprietary\ncopyright=Example Ltd\n");
        $context = $this->repo(['.ckconform' => "license=MIT\n"]);
        self::assertSame('MIT', $context->policyValue('license'));
        self::assertSame('Example Ltd', $context->policyValue('copyright'));
        self::assertStringContainsString("CK_POLICY_COPYRIGHT='Example Ltd'", Policy::toShell("license=MIT\n"));
        self::assertStringContainsString("CK_POLICY_LICENSE='MIT'", Policy::toShell("license=MIT\n"));
    }

    public function testDefaultsApplyToARepoWithoutAPolicyFile(): void
    {
        $this->defaults("license=Proprietary\n");
        self::assertSame('Proprietary', $this->repo([])->policyValue('license'));
    }

    public function testAnUnreadableDefaultsFileIsAnError(): void
    {
        putenv(Policy::DEFAULTS_ENV . '=/nonexistent/ckconform-defaults');
        $this->expectException(\RuntimeException::class);
        Policy::defaultsRaw();
    }

    public function testPolicyKeyCheckReportsTheDefaultsFileByName(): void
    {
        $this->defaults("min_covrage=70\nlicense 1\n");
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([]));
        $this->assertFails($reporter, "unknown key 'min_covrage'");
        $this->assertFails($reporter, 'CK_DEFAULT_POLICY (' . $this->defaultsFile . ')');
        $this->assertFails($reporter, ":2: no KEY=VALUE in 'license 1'");
        self::assertStringNotContainsString('.ckconform:', $reporter->render());
    }

    public function testPolicyKeyCheckFailsOnAMissingDefaultsFile(): void
    {
        putenv(Policy::DEFAULTS_ENV . '=/nonexistent/ckconform-defaults');
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([]));
        $this->assertFails($reporter, 'not a readable file');
    }
}
