<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\JavaScript;

/**
 * The phpstan extension's Api4Catalog as JSON for the oxlint rule, plus the
 * entities and action classes the analysed extensions define themselves.
 */
final class Api4CatalogExport
{
    /**
     * @param list<string> $extensionDirs
     * @return array<string, mixed>
     */
    public static function build(string $catalogFile, array $extensionDirs): array
    {
        require_once $catalogFile;
        $catalog = \CiviKitchen\PHPStan\Api4Catalog::class;

        $entities = [];
        $actions = [];
        foreach ($extensionDirs as $dir) {
            foreach (glob($dir . '/Civi/Api4/*.php') ?: [] as $file) {
                $entities[] = basename($file, '.php');
            }
            // AbstractEntity::__callStatic resolves Civi\Api4\Action\<Entity>\<Action>.
            foreach (glob($dir . '/Civi/Api4/Action/*/*.php') ?: [] as $file) {
                $actions[] = basename(dirname($file)) . '\\' . basename($file, '.php');
            }
        }
        sort($entities);
        sort($actions);

        return [
            'version' => $catalog::CORE_VERSION,
            'entities' => $catalog::ENTITIES,
            'aliases' => $catalog::CLASS_ALIASES,
            'dynamicPrefixes' => $catalog::DYNAMIC_PREFIXES,
            'dynamicFieldPrefixes' => $catalog::DYNAMIC_FIELD_PREFIXES,
            'anyEntityFields' => $catalog::ANY_ENTITY_FIELDS,
            'extensionEntities' => array_values(array_unique($entities)),
            'extensionActions' => array_values(array_unique($actions)),
        ];
    }
}
