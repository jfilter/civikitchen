<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\FloatingTagCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class FloatingTagCheckTest extends CheckTestCase
{
    public function testSilentWithoutAnyWorkflow(): void
    {
        $this->assertSilent($this->run_(new FloatingTagCheck(), $this->repo([])));
    }

    public function testSilentWhenTagsArePinned(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "image: ghcr.io/jfilter/civikitchen:1.2.3\n",
        ]);
        $this->assertSilent($this->run_(new FloatingTagCheck(), $context));
    }

    public function testWarnsOnFloatingImageTagWithExactMessage(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "image: ghcr.io/jfilter/civikitchen:latest\n",
        ]);
        $reporter = $this->run_(new FloatingTagCheck(), $context);
        $this->assertWarns(
            $reporter,
            'CI pins nothing (floating :latest): .github/workflows/ci.yml:1:image: ghcr.io/jfilter/civikitchen:latest'
        );
    }

    public function testWarnsOnReleasesLatestDownload(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "curl -LO https://x/releases/latest/download/tool\n",
        ]);
        $reporter = $this->run_(new FloatingTagCheck(), $context);
        $this->assertWarns($reporter, 'CI pins nothing (floating :latest): .github/workflows/ci.yml:1:');
    }
}
