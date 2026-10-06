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

    private function workflow(string $jobBody): \CiviKitchen\Ckconform\Context
    {
        return $this->repo([
            '.github/workflows/ci.yml' => "on: push\njobs:\n  test:\n    runs-on: ubuntu-latest\n{$jobBody}",
        ]);
    }

    public function testWarnsOnTheContainerShorthand(): void
    {
        $reporter = $this->run_(new FloatingTagCheck(), $this->workflow("    container: ghcr.io/example/ci:latest\n"));
        $this->assertWarns($reporter, '.github/workflows/ci.yml:5:    container: ghcr.io/example/ci:latest');
    }

    public function testWarnsOnAnUntaggedServiceImage(): void
    {
        $reporter = $this->run_(new FloatingTagCheck(), $this->workflow("    services:\n      db:\n        image: mariadb\n"));
        $this->assertWarns($reporter, '.github/workflows/ci.yml:7:        image: mariadb');
    }

    public function testWarnsOnADockerUses(): void
    {
        $reporter = $this->run_(new FloatingTagCheck(), $this->workflow("    steps:\n      - uses: docker://alpine:latest\n"));
        $this->assertWarns($reporter, '.github/workflows/ci.yml:6:      - uses: docker://alpine:latest');
    }

    public function testWarnsOnTheLongContainerForm(): void
    {
        $reporter = $this->run_(new FloatingTagCheck(), $this->workflow("    container:\n      image: ghcr.io/example/ci:latest\n"));
        $this->assertWarns($reporter, '.github/workflows/ci.yml:6:');
    }

    /** runs-on labels and matrix values ending in -latest are no image references. */
    public function testPinnedReferencesAndRunnerLabelsAreSilent(): void
    {
        $context = $this->workflow(
            "    container: ghcr.io/example/ci:1.2.3\n"
            . "    services:\n      db:\n        image: \"mariadb:11.4\"\n      cache:\n        image: \${{ matrix.cache }}\n"
            . "    strategy:\n      matrix:\n        include:\n          - image: ubuntu-latest\n"
            . "    steps:\n      - uses: docker://alpine:3.20\n      - uses: actions/checkout@v4\n"
            . "      # container: example/scratch\n",
        );
        $this->assertSilent($this->run_(new FloatingTagCheck(), $context));
    }

    /** `container:` is a job's container only directly under a job; a comment names no image. */
    public function testContainerKeysOutsideAJobAndCommentsAreSilent(): void
    {
        $context = $this->workflow(
            "    strategy:\n      matrix:\n        include:\n          - container: civicrm\n"
            . "    services:\n      db:\n        image: mariadb:11.4 # not :latest\n"
            . "    steps:\n      - uses: example/deploy@v1\n        with:\n          container: web\n",
        );
        $this->assertSilent($this->run_(new FloatingTagCheck(), $context));
    }
}
