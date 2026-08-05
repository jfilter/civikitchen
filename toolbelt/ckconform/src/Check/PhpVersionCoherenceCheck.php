<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * composer's PHP floor and info.xml's declared PHP support disagreeing.
 *
 * An extension states its PHP requirement in two places: composer.json's
 * require.php (the hard floor composer enforces at install) and info.xml's
 * <php_compatibility mode="list"> (the versions the extension claims to run on).
 * When they drift, one of them lies — composer >=8.1 against a list of 8.3/8.4
 * lets the extension install on an 8.1 site it never promised to support, and
 * the reverse refuses an install the list says is fine.
 *
 * Only the minimum is compared, and only when both are declared: a contradiction
 * between two stated bounds is provable, where inferring a bound from the source
 * would need real version analysis. The floors must match exactly — the lowest
 * PHP composer will install on is, by definition, the lowest the extension
 * supports.
 */
final class PhpVersionCoherenceCheck implements Check
{
    public function name(): string
    {
        return 'php-version-coherence';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $composerFloor = $this->composerFloor($context);
        $compatFloor = $this->compatibilityFloor($context);
        if ($composerFloor === null || $compatFloor === null) {
            return;
        }

        if ($composerFloor !== $compatFloor) {
            $reporter->fail(sprintf(
                'PHP floor disagrees: composer require.php is %s, info.xml php_compatibility starts at %s'
                . ' — composer decides what installs, so the two must state the same minimum',
                $composerFloor,
                $compatFloor
            ));
        } else {
            $reporter->ok('composer and info.xml agree on the PHP floor (' . $composerFloor . ')');
        }

        $this->checkPhpstanFloor($context, $reporter, $composerFloor);
    }

    /**
     * The floor is a promise about where the code RUNS, and nothing checks it
     * against the code itself: phpstan analyses with the interpreter it happens
     * to run under — 8.3 in the image — so an 8.3-only feature in an extension
     * that installs on 8.1 passes every gate and fatals on a customer site.
     * `phpVersion` is what makes phpstan analyse for the floor instead.
     */
    private function checkPhpstanFloor(Context $context, Reporter $reporter, string $floor): void
    {
        $config = $context->readAny('phpstan.neon.dist', 'phpstan.neon');
        if ($config === null) {
            return;
        }
        $config = preg_replace('/^\s*#.*$/m', '', $config) ?? $config;

        // `phpVersion: 80100`, or PHPStan 2's range form `phpVersion: { min: 80100, max: … }`.
        if (preg_match('/phpVersion:\s*(?:\{[^}]*?min:\s*)?(\d{5,6})/', $config, $match) !== 1) {
            $reporter->warn(sprintf(
                'phpstan does not set phpVersion — it analyses with the image PHP, not the declared floor (%s);'
                . ' add `phpVersion: %s` so features newer than the floor are reported',
                $floor,
                $this->phpstanVersionId($floor)
            ));

            return;
        }

        $declared = (int) $match[1];
        if ($declared !== $this->phpstanVersionId($floor)) {
            $reporter->fail(sprintf(
                'phpstan analyses for PHP %s but the extension installs from %s — the lower one is what has to be analysed',
                sprintf('%d.%d', intdiv($declared, 10000), intdiv($declared % 10000, 100)),
                $floor
            ));
        } else {
            $reporter->ok('phpstan analyses for the declared floor (' . $floor . ')');
        }
    }

    /**
     * "8.1" -> 80100, phpstan's phpVersion id.
     */
    private function phpstanVersionId(string $floor): int
    {
        [$major, $minor] = array_map('intval', explode('.', $floor));

        return $major * 10000 + $minor * 100;
    }

    /**
     * The lowest MAJOR.MINOR in composer's require.php constraint, e.g. ">=8.3"
     * or "8.1.2" -> "8.1". The first version token in the constraint is its
     * lower bound for the ranges these repos use (">=x", "^x", "x.*").
     */
    private function composerFloor(Context $context): ?string
    {
        $composer = $context->json('composer.json');
        $constraint = $composer['require']['php'] ?? null;
        if (!is_string($constraint) || preg_match('/(\d+)\.(\d+)/', $constraint, $match) !== 1) {
            return null;
        }

        return $match[1] . '.' . $match[2];
    }

    /**
     * The lowest MAJOR.MINOR listed in info.xml <php_compatibility>.
     */
    private function compatibilityFloor(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        $floor = null;
        foreach ($info->xpath('//php_compatibility/ver') ?: [] as $node) {
            if (preg_match('/(\d+)\.(\d+)/', (string) $node, $match) !== 1) {
                continue;
            }
            $version = $match[1] . '.' . $match[2];
            if ($floor === null || version_compare($version, $floor, '<')) {
                $floor = $version;
            }
        }

        return $floor;
    }
}
