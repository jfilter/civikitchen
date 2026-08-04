<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ComposeProjectNameCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ComposeProjectNameCheckTest extends CheckTestCase
{
    public function testSilentWithoutAComposeFile(): void
    {
        $this->assertSilent($this->run_(new ComposeProjectNameCheck(), $this->repo([], git: true)));
    }

    /** The estate-wide case: every stack in .docker/ resolving to "docker". */
    public function testAComposeFileWithoutANameFails(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "services:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeProjectNameCheck(), $context), 'without an explicit project name');
    }

    public function testAnExplicitNamePasses(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "name: thing-ci\n\nservices:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeProjectNameCheck(), $context));
    }

    /** An empty or commented-out key is not a name. */
    public function testAnEmptyOrCommentedNameDoesNotCount(): void
    {
        foreach (["name:\n", "# name: thing\n"] as $header) {
            $context = $this->repo([
                '.docker/compose.yaml' => $header . "services:\n  app:\n    image: x:1\n",
            ], git: true);
            $this->assertFails($this->run_(new ComposeProjectNameCheck(), $context));
        }
    }

    public function testEveryUnnamedFileIsNamedInTheMessage(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "services:\n  a:\n    image: x:1\n",
            '.docker/docker-compose.yml' => "services:\n  b:\n    image: x:1\n",
        ], git: true);
        $reporter = $this->run_(new ComposeProjectNameCheck(), $context);
        $message = implode("\n", $reporter->messages('FAIL'));
        self::assertStringContainsString('docker-compose.ci.yml', $message);
        self::assertStringContainsString('docker-compose.yml', $message);
    }

    /**
     * A compose file in the repo root derives the repo's own directory name,
     * which is already unique — the collision comes from files tucked into a
     * shared subdirectory like .docker/.
     */
    public function testARootLevelComposeFileNeedsNoExplicitName(): void
    {
        $context = $this->repo([
            'docker-compose.yml' => "services:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeProjectNameCheck(), $context));
    }
}
