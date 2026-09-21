<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MonorepoVersionLockstepCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MonorepoVersionLockstepCheckTest extends CheckTestCase
{
    public function testFailsWhenANeighbourCarriesAnotherVersion(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['info.xml' => $this->versioned('fixture', '1.3.0', '2026-09-01')],
            ['info.xml' => $this->versioned('base', '1.2.0', '2026-09-01')],
        );
        $this->assertFails(
            $this->run_(new MonorepoVersionLockstepCheck(), $context),
            'carry different versions',
        );
    }

    public function testFailsWhenOnlyTheReleaseDateDiffers(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['info.xml' => $this->versioned('fixture', '1.3.0', '2026-09-01')],
            ['info.xml' => $this->versioned('base', '1.3.0', '2026-08-12')],
        );
        $this->assertFails(
            $this->run_(new MonorepoVersionLockstepCheck(), $context),
            'carry different versions',
        );
    }

    public function testPassesInLockstep(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['info.xml' => $this->versioned('fixture', '1.3.0', '2026-09-01')],
            ['info.xml' => $this->versioned('base', '1.3.0', '2026-09-01')],
        );
        $this->assertPasses($this->run_(new MonorepoVersionLockstepCheck(), $context));
    }

    public function testSilentInASingleExtensionRepo(): void
    {
        $context = $this->repo(['info.xml' => $this->versioned('fixture', '1.3.0', '2026-09-01')], git: true);
        $this->assertSilent($this->run_(new MonorepoVersionLockstepCheck(), $context));
    }

    private function versioned(string $key, string $version, string $releaseDate): string
    {
        return $this->infoXml(
            key: $key,
            extra: "  <version>{$version}</version>\n  <releaseDate>{$releaseDate}</releaseDate>",
        );
    }
}
