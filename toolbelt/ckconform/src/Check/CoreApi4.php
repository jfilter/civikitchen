<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/** Where an APIv4 entity class lives outside the extension under inspection. */
final class CoreApi4
{
    /**
     * The entity's class file in core proper or in an extension core bundles
     * (ext/civi_mail ships with core too); null when core has none.
     */
    public static function classFile(string $coreDir, string $entity): ?string
    {
        $direct = $coreDir . '/Civi/Api4/' . $entity . '.php';
        if (is_file($direct)) {
            return $direct;
        }

        foreach ([$coreDir . '/ext', dirname($coreDir) . '/ext'] as $extRoot) {
            if (!is_dir($extRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()
                    && str_ends_with($file->getPathname(), '/Civi/Api4/' . $entity . '.php')
                ) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /** Whether a `<requires>` extension present on disk ships the entity. */
    public static function inRequiredExtension(Context $context, string $entity): bool
    {
        foreach ($context->requiredExtensionDirs() as $dir) {
            if (is_file($dir . '/Civi/Api4/' . $entity . '.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entities civikitchen.yaml declares as supplied by a dependency
     * (known_api4_entities); Api4EntityCheck validates the declaration itself.
     *
     * @return list<string>
     */
    public static function declaredExternal(Context $context): array
    {
        $raw = $context->policyValue('known_api4_entities');

        return $raw === null ? [] : array_values(array_filter(array_map(
            'trim',
            explode(',', \CiviKitchen\Ckconform\Policy::stripReason($raw)),
        )));
    }
}
