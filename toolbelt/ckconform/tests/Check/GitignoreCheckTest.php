<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\GitignoreCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class GitignoreCheckTest extends CheckTestCase
{
    public function testFailsWithoutAGitignore(): void
    {
        $context = $this->repo([], git: true);
        $this->assertFails($this->run_(new GitignoreCheck(), $context), 'no .gitignore');
    }

    public function testPassesWithAGitignore(): void
    {
        $context = $this->repo(['.gitignore' => "vendor/\n"], git: true);
        $this->assertSilent($this->run_(new GitignoreCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new GitignoreCheck(), $context));
    }
}
