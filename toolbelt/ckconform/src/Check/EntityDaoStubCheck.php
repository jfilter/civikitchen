<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * `schema/*.entityType.php` names a DAO class that something has to ship.
 *
 * The schema file is declarative and looks complete on its own, but the class
 * it names under `class` is generated separately — `civix generate:entity`
 * writes the schema AND the `CRM/<Ns>/DAO/<Entity>.php` stub. Hand-write or
 * copy a schema file and the stub is simply absent, with nothing in the repo
 * pointing at the gap: PHPStan does not resolve a class named in a string,
 * the tests pass, and CI is green.
 *
 * It fails at install time instead, as `Class "CRM_Acme_DAO_Thing" not found`
 * thrown out of AbstractEntity while the extension is being enabled — after
 * the earlier entities' tables were already created, so the retry then hits
 * "DB Error: already exists" and the install needs a manual teardown.
 *
 * A stale `$_tableName` is the quieter half: the class resolves, so the enable
 * succeeds, and every query for that entity silently addresses another table.
 */
final class EntityDaoStubCheck implements Check
{
    public function name(): string
    {
        return 'entity-dao-stub';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('schema', ['.entityType.php'])
            : $context->findFiles('schema', ['.entityType.php']);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register();

        $checked = 0;
        foreach ($files as $relative) {
            try {
                $schema = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate entity schema outside CiviCRM ({$e->getMessage()}) — DAO stub unchecked");
                continue;
            }
            if (!is_array($schema)) {
                $reporter->fail("$relative: does not return an entity definition array");
                continue;
            }

            $class = is_string($schema['class'] ?? null) ? trim($schema['class']) : '';
            if ($class === '') {
                $reporter->fail("$relative: no 'class' key — entity-types-php needs the DAO class name to load the entity");
                continue;
            }

            $checked++;
            $this->verify($context, $reporter, $relative, $class, $schema);
        }

        if ($checked > 0 && $reporter->failures() === 0) {
            $reporter->ok("every entity schema ships its DAO stub ($checked entities)");
        }
    }

    /**
     * @param array<mixed> $schema
     */
    private function verify(
        Context $context,
        Reporter $reporter,
        string $relative,
        string $class,
        array $schema,
    ): void {
        $expected = str_replace('_', '/', $class) . '.php';
        if (!$context->ships($expected)) {
            $reporter->fail(
                "$relative: declares class $class but the repo ships no $expected — generate it with `civix generate:entity` (enabling the extension fatals with 'Class $class not found')"
            );

            return;
        }

        $table = is_string($schema['table'] ?? null) ? trim($schema['table']) : '';
        if ($table === '') {
            return;
        }

        $declared = $this->tableName($context->read($expected) ?? '');
        if ($declared !== null && $declared !== $table) {
            $reporter->fail(
                "$expected: \$_tableName is '$declared' but $relative declares table '$table' — the DAO stub is stale and queries would address the wrong table"
            );
        }
    }

    /** The `$_tableName` a DAO stub declares, or null when it declares none. */
    private function tableName(string $source): ?string
    {
        $pattern = '/\$_tableName\s*=\s*([\'"])([A-Za-z0-9_]+)\1\s*;/';

        return preg_match($pattern, $source, $match) === 1 ? $match[2] : null;
    }
}
