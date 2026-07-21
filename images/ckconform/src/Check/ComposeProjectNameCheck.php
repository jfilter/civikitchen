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
 * extension here keeps its stacks in `.docker/`, so thirteen compose files
 * across eight repos all resolved to one project called "docker" — they shared
 * containers, networks and volumes with each other.
 *
 * The damage is not theoretical: `up` in one repo bind-mounted a different
 * repo's checkout into the container, and `down -v` removed a sibling's
 * volumes. It cost a working session and a torn-down stack before anyone
 * noticed, because the failure looks like "my extension directory is missing"
 * rather than "you are in the wrong project".
 *
 * CI never sees it — each runner has one repo — which is exactly why it
 * survived: it only bites the developer with several checkouts.
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
        foreach ($this->composeFiles($context) as $file) {
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

    /**
     * @return list<string>
     */
    private function composeFiles(Context $context): array
    {
        $files = [];
        foreach ($context->trackedFiles() as $file) {
            if (preg_match('/^(docker-)?compose.*\.ya?ml$/', basename($file)) === 1) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }
}
