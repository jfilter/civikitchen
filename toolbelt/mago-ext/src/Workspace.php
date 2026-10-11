<?php

declare(strict_types=1);

namespace CiviKitchen\Mago;

use Mago\Sdk\Syntax\SourceFile;

/**
 * mago hands the worker workspace-relative paths and starts it in the config
 * file's directory: the workspace is CK_MAGO_WORKSPACE when set, else that cwd.
 */
final class Workspace
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        $root = getenv('CK_MAGO_WORKSPACE');

        return rtrim($root === false || $root === '' ? (string) getcwd() : $root, '/');
    }

    public static function absolute(SourceFile|string $file): string
    {
        $path = $file instanceof SourceFile ? $file->path : $file;

        return str_starts_with($path, '/') ? $path : self::root() . '/' . $path;
    }

    /** The nearest directory holding an info.xml at or above the file, within the workspace; null outside an extension. */
    public static function extensionRoot(SourceFile|string $file): ?string
    {
        $root = self::root();
        $dir = dirname(self::absolute($file));
        while (str_starts_with($dir . '/', $root . '/')) {
            if (is_file($dir . '/info.xml')) {
                return $dir;
            }
            if ($dir === $root) {
                break;
            }
            $dir = dirname($dir);
        }

        return null;
    }
}
