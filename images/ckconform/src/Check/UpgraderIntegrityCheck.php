<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An upgrade path that only breaks while it is upgrading.
 *
 * Everything here shares one property: it is invisible on a fresh install and on
 * every test run, and it detonates during `civicrm-ext-upgrade` on a live site —
 * the one moment where the schema is half-migrated and rolling back is a manual
 * job. The classes of breakage found in the wild:
 *
 *  - info.xml declares an <upgrader> class whose file does not exist (renamed
 *    extension, moved namespace): the upgrade fatals before step one.
 *  - Two upgrade steps with the same numeric revision (upgrade_01 and upgrade_1).
 *    Core sorts revisions numerically, so they collide and one is skipped or run
 *    in an undefined order.
 *  - An upgrade_* method that is private or protected. Core calls it from
 *    outside; a non-public step is silently never executed, and the schema
 *    change it carries never lands.
 *  - executeSqlFile() pointing at a .sql file the repo does not ship.
 *  - A committed sql/upgrade_*.sql nobody references — dead weight, or a step
 *    someone forgot to wire up. Only a warning: it cannot break an upgrade, it
 *    can only mean one is missing.
 *  - An Upgrader class that exists but is not declared anywhere: written,
 *    committed, never called.
 *
 * The upgrader is read as text (regex over the source), never autoloaded: the
 * class extends CiviCRM base classes that do not exist outside a boot, so
 * reflection is not available to a static checker.
 */
final class UpgraderIntegrityCheck implements Check
{
    /** Filenames civix executes itself; not referenced from PHP by design. */
    private const SELF_RUN_SQL = ['auto_install.sql', 'auto_uninstall.sql'];

    public function name(): string
    {
        return 'upgrader-integrity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle): an untracked local upgrader
        // cannot break anyone else's upgrade. Outside git, fall back to disk.
        $tracked = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php', '.sql'])
            : $context->findFiles('', ['.php', '.sql']);

        $declared = $this->declaredUpgrader($context);
        $file = null;

        if ($declared === null) {
            $this->reportUndeclaredUpgrader($context, $reporter, $tracked);
        } elseif (($schema = $this->automaticUpgraderShortName($declared)) !== null) {
            // CiviMix\Schema\<X>\AutomaticUpgrader is generated at runtime, so
            // its "file" never exists in the repo. Legal on its own; but if the
            // repo ships CRM/<X>/Upgrader.php it is meant to be the delegating
            // subclass, and its class name has to match.
            $candidate = 'CRM/' . $schema . '/Upgrader.php';
            if (in_array($candidate, $tracked, true)) {
                $file = $candidate;
                $source = $context->read($candidate) ?? '';
                if (!$this->declaresClass($source, 'CRM_' . $schema . '_Upgrader')) {
                    $reporter->fail(
                        $candidate . ' does not declare class CRM_' . $schema . '_Upgrader'
                        . ' — the AutomaticUpgrader delegation in info.xml cannot reach it'
                    );
                }
            }
        } else {
            $file = $this->classFile($context, $declared, $tracked);
            if ($file === null) {
                $reporter->fail(
                    'info.xml <upgrader> declares ' . $declared . ' but no file for that class is committed'
                    . ' — the extension upgrade fatals before its first step'
                );
            }
        }

        if ($file !== null) {
            $this->checkUpgradeSteps($context, $reporter, $file, $tracked);
        }

        $this->reportOrphanSql($context, $reporter, $tracked);
    }

    /** The <upgrader> value from info.xml, backslash-normalised. */
    private function declaredUpgrader(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        foreach ($info->xpath('//upgrader') ?: [] as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                return ltrim(str_replace('/', '\\', $value), '\\');
            }
        }

        return null;
    }

    /** 'CiviMix\Schema\Foo\AutomaticUpgrader' => 'Foo'. */
    private function automaticUpgraderShortName(string $class): ?string
    {
        if (preg_match('/^CiviMix\\\\Schema\\\\([A-Za-z0-9_]+)\\\\AutomaticUpgrader$/', $class, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * The committed file for a class name: PSR-0 for CRM_ (underscores to
     * slashes), PSR-4-ish for namespaced classes (backslashes to slashes).
     * A repo may nest either under a subdirectory, so a suffix match counts.
     *
     * @param list<string> $tracked
     */
    private function classFile(Context $context, string $class, array $tracked): ?string
    {
        $relative = str_contains($class, '\\')
            ? str_replace('\\', '/', $class) . '.php'
            : str_replace('_', '/', $class) . '.php';

        foreach ($tracked as $candidate) {
            if ($candidate === $relative || str_ends_with($candidate, '/' . $relative)) {
                return $candidate;
            }
        }

        return null;
    }

    private function declaresClass(string $source, string $class): bool
    {
        return preg_match('/\b(?:class|interface|trait)\s+' . preg_quote($class, '/') . '\b/', $source) === 1;
    }

    /**
     * Revision collisions, non-public steps and missing SQL files, from the
     * upgrader source. Read as text on purpose — see the class docblock.
     *
     * @param list<string> $tracked
     */
    private function checkUpgradeSteps(Context $context, Reporter $reporter, string $file, array $tracked): void
    {
        $source = $context->read($file);
        if ($source === null) {
            return;
        }

        $pattern = '/(?:^|[\r\n])[ \t]*((?:(?:public|protected|private|static|final|abstract)\s+)*)'
            . 'function\s+(upgrade_(\d+))\s*\(/i';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        /** @var array<int, list<string>> $byRevision */
        $byRevision = [];
        foreach ($matches[2] as $index => [$method, $offset]) {
            $modifiers = strtolower(trim($matches[1][$index][0]));
            $revision = (int) $matches[3][$index][0];
            $byRevision[$revision][] = $method;

            if (str_contains($modifiers, 'private') || str_contains($modifiers, 'protected')) {
                $reporter->fail(
                    $file . ': ' . $method . '() is not public — CiviCRM calls upgrade steps from outside,'
                    . ' so this step never runs and its schema change never lands'
                );
            }

            $this->checkSqlReferences($reporter, $file, $method, $this->methodBody($source, $offset), $tracked);
        }

        foreach ($byRevision as $revision => $methods) {
            if (count($methods) > 1) {
                sort($methods);
                $reporter->fail(
                    $file . ': ' . implode(' and ', $methods) . ' are both revision ' . $revision
                    . ' — core sorts revisions numerically, so these collide and one is lost'
                );
            }
        }
    }

    /**
     * Source from a method declaration up to the next function declaration —
     * enough to see the executeSqlFile() literals that belong to this step.
     */
    private function methodBody(string $source, int $offset): string
    {
        $next = preg_match('/\bfunction\s+\w+\s*\(/', $source, $ignored, PREG_OFFSET_CAPTURE, $offset + 1) === 1
            ? $ignored[0][1]
            : strlen($source);

        return substr($source, $offset, $next - $offset);
    }

    /**
     * @param list<string> $tracked
     */
    private function checkSqlReferences(Reporter $reporter, string $file, string $method, string $body, array $tracked): void
    {
        foreach ($this->sqlLiterals($body) as $reference) {
            if ($this->sqlFile($reference, $tracked) === null) {
                $reporter->fail(
                    $file . ': ' . $method . '() runs executeSqlFile(\'' . $reference . '\')'
                    . ' but that file is not committed — the upgrade fatals mid-step'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function sqlLiterals(string $source): array
    {
        preg_match_all('/executeSqlFile\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * A referenced SQL path resolved against the committed files. References are
     * extension-root relative and may or may not carry the sql/ prefix.
     *
     * @param list<string> $tracked
     */
    private function sqlFile(string $reference, array $tracked): ?string
    {
        $reference = ltrim($reference, '/');
        $candidates = [$reference, 'sql/' . $reference];
        foreach ($candidates as $candidate) {
            foreach ($tracked as $file) {
                if ($file === $candidate || str_ends_with($file, '/' . $candidate)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Committed SQL that no PHP file names. A warning: an unreferenced upgrade
     * script cannot break an upgrade, it can only mean a step is missing.
     *
     * @param list<string> $tracked
     */
    private function reportOrphanSql(Context $context, Reporter $reporter, array $tracked): void
    {
        $php = '';
        foreach ($tracked as $file) {
            if (str_ends_with($file, '.php')) {
                $php .= "\n" . ($context->read($file) ?? '');
            }
        }

        $orphans = [];
        foreach ($tracked as $file) {
            // Only the extension's own sql/ dir is upgrade territory —
            // .docker/ seeds, test fixtures etc. are legitimately PHP-free.
            if (!str_ends_with($file, '.sql') || !str_starts_with($file, 'sql/')) {
                continue;
            }
            $name = basename($file);
            if (in_array($name, self::SELF_RUN_SQL, true)) {
                continue;
            }
            if (!str_contains($php, $name)) {
                $orphans[] = $file;
            }
        }

        if ($orphans !== []) {
            $reporter->warn(
                'committed SQL no PHP references: ' . implode(', ', $orphans)
                . ' — either an upgrade step is missing or the file is a vestige to delete'
            );
        }
    }

    /**
     * An Upgrader class the repo ships but info.xml never declares. Silent for
     * civix-legacy repos, where the .civix shim calls the upgrader from
     * hook_civicrm_upgrade instead of the <upgrader> declaration.
     *
     * @param list<string> $tracked
     */
    private function reportUndeclaredUpgrader(Context $context, Reporter $reporter, array $tracked): void
    {
        $upgraders = [];
        foreach ($tracked as $file) {
            if (preg_match('#(?:^|/)CRM/[^/]+/Upgrader\.php$#', $file) === 1) {
                $upgraders[] = $file;
            }
        }
        if ($upgraders === []) {
            return;
        }

        foreach ($tracked as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $source = $context->read($file) ?? '';
            if (str_ends_with($file, '.civix.php') && str_contains($source, '_upgrade')) {
                return;
            }
            if (preg_match('/\bfunction\s+\w+_civicrm_upgrade\s*\(/', $source) === 1) {
                return;
            }
        }

        $reporter->warn(
            'info.xml has no <upgrader> although ' . implode(', ', $upgraders) . ' is committed'
            . ' — the upgrade steps in it are never called'
        );
    }
}
