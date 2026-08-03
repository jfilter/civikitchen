<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A conventional file that CiviCRM never loads because its mixin is not declared.
 *
 * civix wires each family of convention-over-configuration files through a
 * mixin listed in info.xml: managed records through mgd-php, entity schemas
 * through entity-types-php, xml/Menu routes through menu-xml, and so on. Ship
 * the files but forget the mixin and the files just sit there — the entity is
 * never registered, the menu route 404s — while every test that does not
 * exercise that exact path stays green.
 *
 * This is not hypothetical. One extension shipped an xml/Menu file routing four
 * CiviRules config forms, its actions sent admins straight to those URLs
 * (getExtraDataInputUrl), and no menu-xml mixin and no xmlMenu hook loaded them:
 * configuring one of its actions 404'd, and had since the extension was renamed.
 *
 * scan-classes is the one entry core itself nags about: an entity picked up by
 * Civi\Api4\Service\LegacyEntityScanner instead of the class scanner raises a
 * status-check message ("APIv4 Entities using Legacy Entity Scanner"), so the
 * extension works but pays a full-extension file scan on every request.
 *
 * A warning, not a failure: older civix generated an xmlMenu/managed hook into
 * the .civix shim instead of using a mixin, so a repo on that pattern loads the
 * files a different way and is not broken. Until every mapping has a proven hard
 * failure this stays a prompt for a human — is the mixin missing, or is the file
 * itself a vestige to delete?
 */
final class MixinDeclarationCheck implements Check
{
    /**
     * civix mixin => the artefact family it loads, as (directory, suffix). An
     * empty directory means the suffix may sit anywhere in the tree (schema/ for
     * entities, settings/ for settings). Version-agnostic: mgd-php@1.0.0 and
     * mgd-php@2.0.0 both satisfy "mgd-php".
     *
     * 'direct' restricts the match to immediate children of the directory. Only
     * scan-classes needs it: core's LegacyEntityScanner globs `Civi/Api4/*.php`
     * without recursing, so Civi/Api4/Action/* is not an entity class and is no
     * evidence of a missing mixin.
     *
     * @var array<string, array{dir: string, suffix: string, label: string, direct?: bool}>
     */
    private const REQUIREMENTS = [
        'mgd-php' => ['dir' => 'managed/', 'suffix' => '.mgd.php', 'label' => 'managed records (managed/*.mgd.php)'],
        'entity-types-php' => ['dir' => '', 'suffix' => '.entityType.php', 'label' => 'entity schemas (*.entityType.php)'],
        'menu-xml' => ['dir' => 'xml/Menu/', 'suffix' => '.xml', 'label' => 'menu routes (xml/Menu/*.xml)'],
        'setting-php' => ['dir' => '', 'suffix' => '.setting.php', 'label' => 'settings (*.setting.php)'],
        'ang-php' => ['dir' => 'ang/', 'suffix' => '.ang.php', 'label' => 'Angular modules (ang/*.ang.php)'],
        'scan-classes' => [
            'dir' => 'Civi/Api4/',
            'suffix' => '.php',
            'direct' => true,
            'label' => 'APIv4 entities (Civi/Api4/*.php)',
        ],
    ];

    public function name(): string
    {
        return 'mixin-declaration';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo() || $context->infoXml() === null) {
            return;
        }

        $declared = $this->declaredMixins($context);
        $missing = [];
        foreach (self::REQUIREMENTS as $mixin => $spec) {
            if (in_array($mixin, $declared, true)) {
                continue;
            }
            if ($this->hasArtefact($context, $spec['dir'], $spec['suffix'], $spec['direct'] ?? false)) {
                $missing[] = $spec['label'] . ' need the ' . $mixin . ' mixin';
            }
        }

        if ($missing !== []) {
            $reporter->warn(
                'info.xml ships files no declared mixin loads: ' . implode('; ', $missing)
                . ' — add the mixin, or delete the files if they are a vestige'
            );
        }
    }

    /**
     * Mixin names from info.xml, version stripped (menu-xml@1.0.0 -> menu-xml).
     *
     * @return list<string>
     */
    private function declaredMixins(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }
        $names = [];
        foreach ($info->xpath('//mixins/mixin') ?: [] as $mixin) {
            $value = trim((string) $mixin);
            if ($value === '') {
                continue;
            }
            $names[] = explode('@', $value)[0];
        }

        return $names;
    }

    private function hasArtefact(Context $context, string $dir, string $suffix, bool $direct = false): bool
    {
        foreach ($context->trackedFiles() as $file) {
            if (!str_ends_with($file, $suffix)) {
                continue;
            }
            if ($dir === '') {
                return true;
            }
            $rest = $this->below($file, $dir);
            if ($rest === null) {
                continue;
            }
            if (!$direct || !str_contains($rest, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The part of $file below $dir, or null if $file is not under it at all.
     */
    private function below(string $file, string $dir): ?string
    {
        if (str_starts_with($file, $dir)) {
            return substr($file, strlen($dir));
        }
        $at = strpos($file, '/' . $dir);

        return $at === false ? null : substr($file, $at + strlen($dir) + 1);
    }
}
