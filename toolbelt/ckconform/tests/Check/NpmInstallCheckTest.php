<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\NpmInstallCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class NpmInstallCheckTest extends CheckTestCase
{
    public function testSilentWhenNotAGitRepo(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  build:\n    steps:\n      - run: npm install\n",
        ]);
        $this->assertSilent($this->run_(new NpmInstallCheck(), $context));
    }

    public function testSilentWithoutWorkflows(): void
    {
        $context = $this->repo([], true);
        $this->assertSilent($this->run_(new NpmInstallCheck(), $context));
    }

    public function testSilentWhenCiUsesNpmCi(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  build:\n    steps:\n      - run: npm ci\n",
        ], true);
        $this->assertSilent($this->run_(new NpmInstallCheck(), $context));
    }

    public function testWarnsWhenCiRunsNpmInstall(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  build:\n    steps:\n      - run: npm install\n",
        ], true);
        $reporter = $this->run_(new NpmInstallCheck(), $context);
        $this->assertWarns($reporter, "CI runs 'npm install' — use 'npm ci' so the lockfile is binding");
    }

    public function testSilentWhenOnlyANeighboursJobRunsNpmInstall(): void
    {
        $context = $this->monorepoExtension([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  example:\n"
                . "    defaults:\n      run:\n        working-directory: example\n"
                . "    steps:\n      - run: npm ci\n  base:\n"
                . "    defaults:\n      run:\n        working-directory: base\n"
                . "    steps:\n      - run: npm install\n",
        ], [], ['Civi/Neighbour.php' => '<?php']);
        $this->assertSilent($this->run_(new NpmInstallCheck(), $context));
    }
}
