<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Floating image tags in the compose stacks.
 *
 * FloatingTagCheck covers the workflow files; the stacks CI actually brings up
 * were unwatched, and that is where it bit. On 2026-07-06 `maildev/maildev:latest`
 * moved to 3.0.0-rc.1, whose built-in healthcheck queries a route the app answers
 * with 404. Every stack pinning `:latest` stopped coming up — with no diff in any
 * repo to point at, and the same commit green the day before.
 *
 * A missing tag is the same defect spelled shorter: `image: mariadb` means
 * `mariadb:latest`.
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
        $files = $this->composeFiles($context);
        if ($files === []) {
            return;
        }

        $floating = [];
        foreach ($files as $file) {
            foreach (explode("\n", $context->read($file) ?? '') as $index => $line) {
                $image = $this->floatingImage($line);
                if ($image !== null) {
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
     * The image reference on this line if it floats, otherwise null.
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
        $image = $match[1];

        // Interpolated defaults (${CIVIKITCHEN_IMAGE:-ghcr.io/...:standalone})
        // are the project's own moving tag by design — the stack is meant to
        // track it, and it is built from this very repo.
        if (str_starts_with($image, '$')) {
            return null;
        }
        if (str_ends_with($image, ':latest')) {
            return $image;
        }
        // No tag at all is ':latest' spelled shorter. A digest (@sha256:...) is
        // pinned as hard as it gets.
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');
        $hasTag = $lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash);
        if (!$hasTag && !str_contains($image, '@')) {
            return $image;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function composeFiles(Context $context): array
    {
        $files = [];
        foreach ($context->trackedFiles() as $file) {
            $name = basename($file);
            if (preg_match('/^(docker-)?compose.*\.ya?ml$/', $name) === 1) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }
}
