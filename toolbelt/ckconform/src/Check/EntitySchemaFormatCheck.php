<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Legacy entity schema formats that a modern floor no longer needs.
 *
 * `schema/*.entityType.php` (loaded by entity-types-php@2, core 5.73+) became
 * the canonical entity format in core 5.75; the XML schema documentation is
 * archived. XML keeps working — core promises compatibility — so both findings
 * are WARNs, and both are version-aware: an extension whose compatibility
 * floor is below 5.75 may legitimately stay on the old format.
 *
 * Migration is `civix upgrade` + `civix convert-entity`, and it touches the
 * mixin, the upgrader and the DAO stubs — verify fresh install AND upgrade.
 */
final class EntitySchemaFormatCheck implements Check
{
    private const CANONICAL_SINCE = '5.75';

    public function name(): string
    {
        return 'entity-schema-format';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $floor = $this->compatibilityFloor($context);
        if ($floor === null || version_compare($floor, self::CANONICAL_SINCE, '<')) {
            return;
        }

        $xmlSchema = array_filter(
            $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles(''),
            static fn (string $f): bool => str_starts_with($f, 'xml/schema/'),
        );
        if ($xmlSchema !== []) {
            $reporter->warn(sprintf(
                'xml/schema/ holds %d legacy entity schema file(s) — for a %s+ floor the canonical format is schema/*.entityType.php (entity-types-php@2); migrate with a current civix (`civix upgrade`, `civix convert-entity`) and verify fresh install plus upgrade',
                count($xmlSchema),
                self::CANONICAL_SINCE,
            ));
        }

        foreach ($context->declaredMixins() as $mixin) {
            if (str_starts_with($mixin, 'entity-types-php@1')) {
                $reporter->warn(
                    "info.xml declares {$mixin} — entity-types-php@2 loads the canonical schema/*.entityType.php format; part of the same civix migration"
                );
            }
        }
    }

    /** The lowest <compatibility><ver> from info.xml, or null when absent. */
    private function compatibilityFloor(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        $floor = null;
        foreach ($info->xpath('//compatibility/ver') ?: [] as $ver) {
            $value = trim((string) $ver);
            if ($value === '' || preg_match('/^\d+(\.\d+)*$/', $value) !== 1) {
                continue;
            }
            if ($floor === null || version_compare($value, $floor, '<')) {
                $floor = $value;
            }
        }

        return $floor;
    }
}
