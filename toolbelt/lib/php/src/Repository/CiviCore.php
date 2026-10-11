<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Repository;

use CiviKitchen\Toolbelt\Process\Runner;

final class CiviCore
{
    public const MISSING = "no CiviCRM core at CIVICRM_CORE_DIR, /var/www/html/core or cv's [civicrm.root] - set CIVICRM_CORE_DIR.";

    /** CIVICRM_CORE_DIR, else the image's core (every dev image provides it), else a booted site's via cv. */
    public static function locate(Runner $runner, string $imageCore = '/var/www/html/core'): ?string
    {
        $configured = (string) getenv('CIVICRM_CORE_DIR');
        if ($configured !== '') {
            return is_file(rtrim($configured, '/') . '/Civi.php') ? rtrim($configured, '/') : null;
        }
        if (is_file($imageCore . '/Civi.php')) {
            return $imageCore;
        }
        $cv = $runner->capture(['cv', 'path', '-d', '[civicrm.root]']);
        $root = rtrim(trim($cv['output']), '/');
        return $cv['status'] === 0 && $root !== '' && is_file($root . '/Civi.php') ? $root : null;
    }

    /** Core's dependencies: its own vendor/, or the project's when core is itself a package (Drupal builds). */
    public static function vendor(string $core): ?string
    {
        if (is_dir($core . '/vendor')) {
            return $core . '/vendor';
        }
        // The buildkit images link a package core to /var/www/html/core.
        return self::packageVendor($core) ?? self::packageVendor(realpath($core) ?: $core);
    }

    private static function packageVendor(string $core): ?string
    {
        $project = dirname($core, 2);
        return str_ends_with($core, '/civicrm/civicrm-core') && basename($project) === 'vendor' ? $project : null;
    }
}
