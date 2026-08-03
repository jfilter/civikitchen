<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PhpVersionCoherenceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PhpVersionCoherenceCheckTest extends CheckTestCase
{
    private function repoWith(?string $composerPhp, ?string $compatVers, ?string $phpstan = null): \CiviKitchen\Ckconform\Context
    {
        $files = [];
        if ($composerPhp !== null) {
            $files['composer.json'] = '{"name":"x","require":{"php":"' . $composerPhp . '"}}';
        }
        if ($phpstan !== null) {
            $files['phpstan.neon.dist'] = $phpstan;
        }
        $compat = $compatVers === null ? '' : "  <php_compatibility mode=\"list\">\n" . $compatVers . "  </php_compatibility>\n";
        $files['info.xml'] = "<?xml version=\"1.0\"?>\n<extension key=\"ext\" type=\"module\">\n" . $compat . "</extension>\n";

        return $this->repo($files, git: true);
    }

    public function testMatchingFloorsPass(): void
    {
        $context = $this->repoWith('>=8.3', "    <ver>8.3</ver>\n    <ver>8.4</ver>\n");
        $this->assertPasses($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    /** The mess this catches: composer looser than the declared support list. */
    public function testAComposerFloorBelowTheListFails(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.3</ver>\n    <ver>8.4</ver>\n");
        $this->assertFails($this->run_(new PhpVersionCoherenceCheck(), $context), 'PHP floor disagrees');
    }

    public function testAComposerFloorAboveTheListFails(): void
    {
        $context = $this->repoWith('>=8.4', "    <ver>8.3</ver>\n    <ver>8.4</ver>\n");
        $this->assertFails($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    /** A patch-level constraint compares on major.minor. */
    public function testPatchLevelConstraintNormalises(): void
    {
        $context = $this->repoWith('8.3.2', "    <ver>8.3</ver>\n");
        $this->assertPasses($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    public function testSilentWhenComposerHasNoPhp(): void
    {
        $context = $this->repoWith(null, "    <ver>8.3</ver>\n");
        $this->assertSilent($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    public function testSilentWhenInfoHasNoPhpCompatibility(): void
    {
        $context = $this->repoWith('>=8.3', null);
        $this->assertSilent($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    public function testPhpstanAnalysingForTheFloorPasses(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.1</ver>\n", "parameters:\n\tlevel: 10\n\tphpVersion: 80100\n");
        $this->assertPasses($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    /** The mess this catches: 8.3-only syntax shipping to an 8.1 site. */
    public function testPhpstanAnalysingAboveTheFloorFails(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.1</ver>\n", "parameters:\n\tphpVersion: 80300\n");
        $this->assertFails($this->run_(new PhpVersionCoherenceCheck(), $context), 'analyses for PHP 8.3');
    }

    public function testPhpstanRangeFormComparesOnItsMinimum(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.1</ver>\n", "parameters:\n\tphpVersion: { min: 80100, max: 80400 }\n");
        $this->assertPasses($this->run_(new PhpVersionCoherenceCheck(), $context));
    }

    public function testMissingPhpVersionWarns(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.1</ver>\n", "parameters:\n\tlevel: 10\n");
        $this->assertWarns($this->run_(new PhpVersionCoherenceCheck(), $context), 'does not set phpVersion');
    }

    /** A commented-out setting is not a setting. */
    public function testCommentedPhpVersionWarns(): void
    {
        $context = $this->repoWith('>=8.1', "    <ver>8.1</ver>\n", "parameters:\n\t# phpVersion: 80300\n");
        $this->assertWarns($this->run_(new PhpVersionCoherenceCheck(), $context), 'does not set phpVersion');
    }
}
