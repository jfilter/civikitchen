<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Declared dependencies versus what the code actually uses.
 *
 * A missing <ext> only bites on a fresh site: the extension installs, then
 * fatals on a SearchKit entity that isn't there — so nothing catches it during
 * normal development, where the dependency happens to be installed anyway.
 *
 * The bash version compared the <requires> block as a substring, which is both
 * too loose (a key that is a prefix of another satisfies it) and, in the
 * ad-hoc variant that once shipped, too strict: an `<ext>[^<]+</ext>` regex
 * missed `<ext version="3.32">org.civicoop.civirules</ext>` because the element
 * carried an attribute. Here the <requires><ext> children are read via
 * SimpleXML and compared as exact, trimmed keys.
 *
 * Only failures are reported — a satisfied dependency is silent, as in bash.
 */
final class RequiredExtensionsCheck implements Check
{
    public function name(): string
    {
        return 'required-extensions';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $required = $this->requiredExtensions($context);

        if ($this->shipsSearchKitEntities($context)) {
            $this->needsExt(
                $reporter,
                $required,
                'org.civicrm.search_kit',
                'managed/ ships SavedSearch/SearchDisplay entities',
            );
        }

        if ($this->shipsAfforms($context)) {
            $this->needsExt($reporter, $required, 'org.civicrm.afform', 'ang/ ships Afforms');
        }

        if ($this->extendsCiviRules($context)) {
            $this->needsExt(
                $reporter,
                $required,
                'org.civicoop.civirules',
                'PHP extends CiviRules base classes',
            );
        }
    }

    /**
     * @param list<string> $required
     */
    private function needsExt(Reporter $reporter, array $required, string $key, string $reason): void
    {
        if (!in_array($key, $required, true)) {
            $reporter->fail("info.xml does not <requires> {$key} — {$reason}");
        }
    }

    /**
     * The <ext> children of <requires>, trimmed. Attributes on the element are
     * irrelevant to its text content, which is exactly what the regex got wrong.
     *
     * @return list<string>
     */
    private function requiredExtensions(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }

        $keys = [];
        foreach ($info->xpath('//requires/ext') ?: [] as $ext) {
            $key = trim((string) $ext);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Managed entities are declared in .mgd.php files, where the entity name is
     * a single-quoted string — that is the literal bash grepped for, kept.
     * Recursive: repos nest managed/ by entity type.
     */
    private function shipsSearchKitEntities(Context $context): bool
    {
        foreach ($context->trackedUnder('managed') as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            if (str_contains($contents, "'SavedSearch'") || str_contains($contents, "'SearchDisplay'")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursive on purpose: a flat ang/*.aff.html glob missed the common
     * ang/afform/*.aff.html layout, and the check passed on a repo full of
     * Afforms with no <ext> for them.
     */
    private function shipsAfforms(Context $context): bool
    {
        return $context->trackedUnder('ang', ['.aff.html', '.aff.json']) !== [];
    }

    private function extendsCiviRules(Context $context): bool
    {
        foreach ($context->trackedUnder('', ['.php']) as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            if (
                str_contains($contents, 'extends CRM_Civirules_')
                || str_contains($contents, 'extends CRM_CivirulesActions_')
            ) {
                return true;
            }
        }

        return false;
    }
}
