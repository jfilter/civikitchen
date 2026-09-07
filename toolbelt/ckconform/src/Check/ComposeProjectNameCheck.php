<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A compose file without an explicit project name.
 *
 * Compose derives the project from the directory the file sits in. Every
 * extension keeps its stacks in `.docker/`, so all of them resolve to one
 * project called "docker" and share containers, networks and volumes: `up` in
 * one repo bind-mounts another's checkout, `down -v` removes a sibling's
 * volumes. CI never sees it (one repo per runner); it bites the developer with
 * several checkouts.
 */
final class ComposeProjectNameCheck implements Check
{
    public function name(): string
    {
        return 'compose-project-name';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $unnamed = [];
        foreach ($context->composeFiles() as $file) {
            // A file in the repo root derives the repo's own directory name,
            // which is already unique — only the ones tucked into a shared
            // subdirectory (.docker/) collide with their siblings.
            if (!str_contains($file, '/')) {
                continue;
            }
            $contents = $context->read($file) ?? '';
            // [ \t] rather than \s: \s matches the newline, so a bare "name:"
            // would be satisfied by the first word of the NEXT line.
            if (preg_match('/^name:[ \t]*\S/m', $contents) !== 1) {
                $unnamed[] = $file;
            }
        }

        if ($unnamed === []) {
            return;
        }

        $reporter->fail(
            'compose file without an explicit project name: ' . implode(', ', $unnamed)
            . ' — compose falls back to the directory name, so every stack kept in .docker/'
            . ' shares one project'
        );
    }
}
