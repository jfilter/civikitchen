<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An APIv4 entity called by name from JavaScript that exists nowhere.
 *
 * Api4EntityCheck reads PHP and asks core about `\Civi\Api4\Foo`. It is blind to
 * the case that actually happened here: a React component calling
 * `getEntities('InflowAdapter', ...)` — an entity nobody ever wrote. Every
 * request 500s, and a `catch {}` two lines below swapped in a hardcoded list
 * under the comment "Fallback to default adapters if API is not available". The
 * API was never available, so the fallback was the only code path that ever ran,
 * and it read as deliberate for as long as nobody looked.
 *
 * Following the name through the frontend's wrappers would take real dataflow
 * analysis: `getEntities(entity)` -> `apiCall(entity, action)` ->
 * `fetch(/civicrm/ajax/api4/${entity}/${action})`. So this does not try. It asks
 * a blunter question of every string handed to a call: does anything by that
 * name exist — here or in core?
 *
 * Everything hinges on not crying wolf, since "CamelCase string in an argument"
 * describes plenty of innocent code. Three conditions together earn a finding:
 *
 *   - the literal is the FIRST argument of a call, where an entity name goes;
 *   - it is multi-word CamelCase (InflowAdapter, not Email or Hello) — the
 *     shape of an entity class, and rare for a label or a key;
 *   - core does not have it and neither do we.
 *
 * An earlier cut matched every quoted string starting with the extension's own
 * prefix. It flagged SearchDisplay names, ScheduledJob names and entityType
 * plurals across four repos — the rule has to recognise an entity reference,
 * not merely a familiar-looking word.
 */
final class Api4SelfEntityCheck implements Check
{
    /** Built artefacts restate the source; a finding there is the same one twice. */
    private const SKIP = ['dist/', 'node_modules/', 'vendor/', 'packages/', 'build/'];

    private const EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'mjs'];

    public function name(): string
    {
        return 'api4-self-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Without core there is no way to tell a missing entity from one we
        // simply do not define ourselves, and guessing would mean noise.
        if ($context->coreDir === null || !$context->isGitRepo()) {
            return;
        }

        $dangling = [];
        $scanned = false;
        foreach ($context->trackedFiles() as $file) {
            if (!$this->scannable($file)) {
                continue;
            }
            $scanned = true;
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            foreach ($this->candidates($source) as $name) {
                if ($this->definedLocally($context, $name) || $this->existsInCore($context, $name)) {
                    continue;
                }
                $dangling[$name][$file] = true;
            }
        }

        if (!$scanned) {
            return;
        }

        if ($dangling === []) {
            $reporter->ok('every APIv4 entity named from JavaScript exists');

            return;
        }

        $parts = [];
        foreach ($dangling as $name => $files) {
            $parts[] = $name . ' (' . implode(', ', array_keys($files)) . ')';
        }
        $reporter->fail(
            'JavaScript calls APIv4 entities that exist nowhere: ' . implode('; ', $parts)
            . ' — every such request 500s, and whatever catches it is hiding that'
        );
    }

    /**
     * String literals in first-argument position that have the shape of an
     * entity class name.
     *
     * @return list<string>
     */
    private function candidates(string $source): array
    {
        // ident, an optional generic (getEntities<Foo>(...)), then the literal.
        $pattern = '/[A-Za-z_$][A-Za-z0-9_$]*\s*(?:<[^<>()]*>)?\s*\(\s*'
            . '[\'"]([A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+)+)[\'"]/';
        preg_match_all($pattern, $source, $matches);

        return array_values(array_unique($matches[1]));
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
     * ext/civi_mail is still shipped with core.
     */
    private function existsInCore(Context $context, string $entity): bool
    {
        $coreDir = (string) $context->coreDir;
        if (is_file($coreDir . '/Civi/Api4/' . $entity . '.php')) {
            return true;
        }

        foreach ([$coreDir . '/ext', dirname($coreDir) . '/ext'] as $extRoot) {
            if (!is_dir($extRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo
                    && $file->isFile()
                    && str_ends_with($file->getPathname(), '/Civi/Api4/' . $entity . '.php')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function scannable(string $file): bool
    {
        foreach (self::SKIP as $directory) {
            if (str_starts_with($file, $directory) || str_contains($file, '/' . $directory)) {
                return false;
            }
        }
        // Assertion DSLs put arbitrary strings in first-argument position —
        // `expect(text).not.toBe('TitleParagraph')` is not an API call, and no
        // amount of shape-matching can tell it from one. Only shipped code can
        // 500 in a browser, so that is what this reads.
        if (preg_match('#(?:^|/)(tests?|__tests__)/#', $file) === 1
            || preg_match('/\.(test|spec)\.[jt]sx?$/', $file) === 1) {
            return false;
        }

        return in_array(pathinfo($file, PATHINFO_EXTENSION), self::EXTENSIONS, true);
    }
}
