<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/**
 * Whether a .gitignore guards the extension: its own, or one in a directory
 * above it inside the same repository, as in a repository of several extensions.
 */
final class GitignoreScope
{
    public static function applies(Context $context): bool
    {
        $top = $context->repositoryRoot();
        $directory = (string) realpath($context->path(''));
        while ($directory !== '') {
            if (is_file($directory . '/.gitignore')) {
                return true;
            }
            if ($directory === $top || dirname($directory) === $directory) {
                return false;
            }
            $directory = dirname($directory);
        }

        return false;
    }
}
