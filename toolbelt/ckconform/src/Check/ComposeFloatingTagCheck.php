<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * Floating image tags in the compose stacks.
 *
 * FloatingTagCheck covers the workflow files; this covers the stacks CI brings
 * up. A `:latest` that moves (a maildev release whose built-in healthcheck
 * queries a 404 route) stops every stack coming up with no diff to point at.
 *
 * A missing tag is the same defect spelled shorter: `image: mariadb` means
 * `mariadb:latest`. A service with `build:` names its own image and is exempt.
 *
 * This is a FAIL rather than the warning its workflow counterpart emits: a
 * floating tag in CI makes a run unattributable, but a floating tag in the stack
 * that CI boots stops the run happening at all.
 */
final class ComposeFloatingTagCheck implements Check
{
    public function name(): string
    {
        return 'compose-floating-tag';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->composeFiles();
        if ($files === []) {
            return;
        }

        $floating = [];
        foreach ($files as $file) {
            $contents = $context->read($file) ?? '';
            $built = $this->builtImages($contents);
            foreach (explode("\n", $contents) as $index => $line) {
                $image = $this->floatingImage($line);
                if ($image !== null && !in_array($image, $built, true)) {
                    $floating[] = sprintf('%s:%d %s', $file, $index + 1, $image);
                }
            }
        }

        if ($floating !== []) {
            $reporter->fail(
                'compose pins nothing: ' . implode(', ', array_slice($floating, 0, 3))
                . (count($floating) > 3 ? sprintf(' (+%d more)', count($floating) - 3) : '')
            );
        } else {
            $reporter->ok('every compose image is pinned to a version');
        }
    }

    /**
     * Images of services that also carry `build:` — compose tags what it builds
     * with that name and pulls nothing.
     *
     * @return list<string>
     */
    private function builtImages(string $contents): array
    {
        $services = Policy::parseYaml($contents)['services'] ?? null;
        $built = [];
        foreach (is_array($services) ? $services : [] as $service) {
            if (is_array($service) && isset($service['build']) && is_string($service['image'] ?? null)) {
                $built[] = $service['image'];
            }
        }

        return $built;
    }

    /**
     * The image reference on this line, unquoted, if it floats, otherwise null.
     */
    private function floatingImage(string $line): ?string
    {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '#')) {
            return null;
        }
        if (preg_match('/^image:\s*(\S+)/', $trimmed, $match) !== 1) {
            return null;
        }

        // Interpolated defaults (${CIVIKITCHEN_IMAGE:-ghcr.io/...:standalone}) are
        // the project's own moving tag by design; ImageReference leaves them alone.
        return ImageReference::floats($match[1]) ? ImageReference::unquote($match[1]) : null;
    }
}
