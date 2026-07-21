<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * APIv4 entities that do not exist, or that exist only in a core newer than the
 * one this extension claims to support.
 *
 * A `\Civi\Api4\Foo` reference resolves at runtime, so a migration can move code
 * onto an entity core never shipped and phpstan, phpcs and every test that does
 * not load that page stay green. This is not hypothetical: an api3->v4 pass moved
 * two pages onto \Civi\Api4\MailingAB, which core exposes only @since 6.17 (from
 * ext/civi_mail, and even there as a bare DAOEntity). The extension declared 6.10
 * and the suite was green — the pages would have fatalled on every live site.
 *
 * Hence two questions, not one:
 *   1. does the entity exist at all?
 *   2. does it exist in the OLDEST core we promise to run on?
 *
 * Only (1) is answerable from the container alone, and answering only (1) is a
 * check on our build rather than on the customer's site.
 */
final class Api4EntityCheck implements Check
{
    /**
     * Namespace segments under Civi\Api4\ that are not entities.
     */
    private const NOT_ENTITIES = ['Generic', 'Action', 'Utils', 'Query', 'Service', 'Event'];

    public function name(): string
    {
        return 'api4-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->coreDir === null || !$context->isGitRepo()) {
            return;
        }

        $declaredVer = $this->declaredVersion($context);
        $missing = [];
        $tooNew = [];
        $undeclared = [];

        foreach ($this->referencedEntities($context) as $entity) {
            // Entities the extension defines itself are its own business.
            if ($this->definedLocally($context, $entity)) {
                continue;
            }

            $classFile = $this->locateInCore($context->coreDir, $entity);
            if ($classFile === null) {
                $missing[] = $entity;
                continue;
            }

            $provider = $this->providingExtension($classFile);
            if ($provider !== null && !in_array($provider, $this->declaredRequires($context), true)) {
                $undeclared[$provider] = $entity;
            }

            if ($declaredVer === null) {
                continue;
            }
            $since = $this->parseSince($classFile);
            if ($since !== null && version_compare($since, $declaredVer, '>')) {
                $tooNew[] = sprintf('%s(@since %s)', $entity, $since);
            }
        }

        foreach ($undeclared as $provider => $entity) {
            $reporter->warn(sprintf(
                'info.xml does not <requires> %s — \\Civi\\Api4\\%s comes from that extension',
                $provider,
                $entity
            ));
        }

        if ($missing !== []) {
            $reporter->fail(
                'APIv4 entities referenced but not found in core or this extension: '
                . implode(' ', $missing)
            );
        } else {
            $reporter->ok('every referenced APIv4 entity exists');
        }

        if ($tooNew !== []) {
            $reporter->fail(sprintf(
                'APIv4 entities newer than the declared <ver>%s</ver>: %s — they fatal on every supported site below that',
                $declaredVer,
                implode(' ', $tooNew)
            ));
        } elseif ($declaredVer !== null) {
            $reporter->ok('every referenced APIv4 entity exists as of the declared core ' . $declaredVer);
        }
    }

    /**
     * The oldest core the extension promises to run on.
     */
    private function declaredVersion(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        $versions = $info->xpath('//compatibility/ver') ?: [];
        $value = isset($versions[0]) ? trim((string) $versions[0]) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Entity names referenced as \Civi\Api4\Foo anywhere in the extension's PHP.
     *
     * @return list<string>
     */
    private function referencedEntities(Context $context): array
    {
        $entities = [];
        foreach ($context->tracked('*.php') as $file) {
            if (str_contains($file, '.civix.php') || str_contains($file, '/DAO/')) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null || !str_contains($source, 'Civi\\Api4\\')) {
                continue;
            }
            // Strip comments first: `\Civi\Api4\Xxx` written in a docblock to
            // name the pattern in prose ("rather than the concrete \Civi\Api4\Xxx
            // classes") is not a reference to an entity, and Xxx does not exist.
            // A check tripped by prose is the mirror of one satisfied by it.
            $source = $this->withoutComments($source);
            // Fully qualified (\Civi\Api4\Foo) or imported (use Civi\Api4\Foo).
            // A bare `Civi\Api4\Foo` without the leading backslash is NOT a
            // reference to the entity — inside a namespaced file it resolves
            // relative to that namespace — so it is usually prose in a comment.
            preg_match_all('/\\\\Civi\\\\Api4\\\\([A-Z][A-Za-z0-9_]*)/', $source, $qualified);
            preg_match_all('/^\s*use\s+Civi\\\\Api4\\\\([A-Z][A-Za-z0-9_]*)/m', $source, $imported);
            foreach ([...$qualified[1], ...$imported[1]] as $name) {
                if (!in_array($name, self::NOT_ENTITIES, true)) {
                    $entities[$name] = true;
                }
            }
        }
        $names = array_keys($entities);
        sort($names);

        return $names;
    }

    /**
     * PHP source with comment bodies blanked, so an entity named only in prose
     * is not read as a reference. Through the tokenizer, since a regex cannot
     * tell a `//` inside a string from one that starts a comment.
     */
    private function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                // Keep newlines so line-based tooling and @since parsing elsewhere
                // still line up; drop everything else in the comment.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function definedLocally(Context $context, string $entity): bool
    {
        foreach ($context->trackedFiles() as $file) {
            if (str_ends_with($file, 'Civi/Api4/' . $entity . '.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Core proper, then the extensions core bundles — an entity living in
     * ext/civi_mail is still shipped with core, and is exactly the case that
     * makes this subtle.
     */
    private function locateInCore(string $coreDir, string $entity): ?string
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
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/Civi/Api4/' . $entity . '.php')) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * The @since tag from the class docblock.
     *
     * Read through the PHP tokenizer rather than by regex over the whole file:
     * the first cut of this rule parsed the docblock with sed's \+, which BSD sed
     * does not support and silently ignores — so the version came back empty and
     * the check passed on every entity instead of failing. A rule that cannot
     * fail is worse than no rule.
     */
    private function parseSince(string $file): ?string
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            if (preg_match('/@since\s+(\d+\.\d+(?:\.\d+)?)/', $token[1], $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * The extension key that provides this entity, when it is not core proper.
     *
     * Extensions tagged `mgmt:required` or `component` are core plumbing that is
     * always present (search_kit, flexmailer, civi_mail) — no core extension
     * declares those, and demanding it would be noise nobody reads. What is left
     * is a genuine optional dependency: riverlea, and anything like it.
     */
    private function providingExtension(string $classFile): ?string
    {
        if (!preg_match('#/ext/(.+?)/(?:[^/]+/)*Civi/Api4/#', $classFile, $match)) {
            return null;
        }
        $dir = substr($classFile, 0, strpos($classFile, '/Civi/Api4/') ?: 0);
        while ($dir !== '' && !is_file($dir . '/info.xml')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
        $info = @simplexml_load_file($dir . '/info.xml');
        if ($info === false) {
            return null;
        }
        foreach ($info->xpath('//tags/tag') ?: [] as $tag) {
            if (in_array(trim((string) $tag), ['mgmt:required', 'component'], true)) {
                return null;
            }
        }
        $key = trim((string) ($info['key'] ?? ''));

        return $key === '' ? null : $key;
    }

    /** @return list<string> */
    private function declaredRequires(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }
        $keys = [];
        foreach ($info->xpath('//requires/ext') ?: [] as $ext) {
            $keys[] = trim((string) $ext);
        }

        return $keys;
    }
}
