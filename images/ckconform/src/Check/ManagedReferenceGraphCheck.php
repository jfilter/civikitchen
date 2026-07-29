<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * SearchKit and Afform configuration is glued together by NAMES, and nothing
 * validates the glue at install time. A SearchDisplay whose
 * `saved_search_id.name` names a SavedSearch that does not exist is created
 * without complaint — the implicit-join lookup just resolves to nothing — and
 * the failure only appears when a user opens the page: an empty display, or a
 * DB error on a NULL saved_search_id. Same for an Afform layout that embeds
 * `<crm-search-display search-name="..." display-name="...">`: a typo or a
 * search that was renamed in one file and not the other produces a form that
 * renders a blank hole.
 *
 * This is the classic aftermath of a rename: the SavedSearch got the new name,
 * the display and the .aff.html kept the old one. Both files still look
 * plausible on their own, so only a cross-file graph catches it.
 *
 * A dangling target whose name starts with this extension's own prefix must be
 * shipped here, so it is a failure. A foreign-looking name may legitimately
 * come from another extension, so it is only a warning — with the reminder that
 * info.xml's <requires> then has to cover that extension.
 */
final class ManagedReferenceGraphCheck implements Check
{
    public function name(): string
    {
        return 'managed-reference-graph';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $mgdFiles = $context->isGitRepo()
            ? $context->trackedUnder('', ['.mgd.php'])
            : $context->findFiles('', ['.mgd.php']);
        $afformFiles = $context->isGitRepo()
            ? $context->trackedUnder('ang', ['.aff.html'])
            : $context->findFiles('ang', ['.aff.html']);
        if ($mgdFiles === [] && $afformFiles === []) {
            return;
        }

        self::registerExtensionUtilStub();

        $prefix = self::shortName($context);
        /** @var array<string, list<string>> $names entity => defined names */
        $names = ['SavedSearch' => [], 'SearchDisplay' => [], 'Afform' => [], 'Navigation' => []];
        /** @var list<array{file: string, label: string, target: string}> $displayRefs */
        $displayRefs = [];

        foreach ($mgdFiles as $relative) {
            try {
                $records = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate managed file outside CiviCRM ({$e->getMessage()}) — reference graph unchecked");
                continue;
            }
            if (!is_array($records)) {
                continue;
            }

            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $entity = $record['entity'] ?? null;
                $params = $record['params'] ?? null;
                $values = is_array($params)
                    ? (is_array($params['values'] ?? null) ? $params['values'] : $params)
                    : [];

                $bucket = match ($entity) {
                    'SavedSearch' => 'SavedSearch',
                    'SearchDisplay' => 'SearchDisplay',
                    'Afform', 'AfformBlock' => 'Afform',
                    'Navigation' => 'Navigation',
                    default => null,
                };
                if ($bucket !== null && is_string($values['name'] ?? null)) {
                    $names[$bucket][] = $values['name'];
                }

                if ($entity === 'SearchDisplay') {
                    // Either the implicit-join form or a plain name string.
                    $target = $values['saved_search_id.name'] ?? null;
                    if (!is_string($target) && is_string($values['saved_search_id'] ?? null)) {
                        $target = $values['saved_search_id'];
                    }
                    if (is_string($target) && $target !== '') {
                        $label = is_string($record['name'] ?? null)
                            ? "SearchDisplay '{$record['name']}'"
                            : "SearchDisplay #$index";
                        $displayRefs[] = ['file' => $relative, 'label' => $label, 'target' => $target];
                    }
                }
            }
        }

        foreach ($displayRefs as $ref) {
            if (in_array($ref['target'], $names['SavedSearch'], true)) {
                continue;
            }
            self::report(
                $reporter,
                $prefix,
                $ref['target'],
                "{$ref['file']}: {$ref['label']} references SavedSearch '{$ref['target']}'",
            );
        }

        foreach ($afformFiles as $relative) {
            $html = $context->read($relative) ?? '';
            foreach (self::searchDisplayTags($html) as $tag) {
                if ($tag['search'] !== null && !in_array($tag['search'], $names['SavedSearch'], true)) {
                    self::report(
                        $reporter,
                        $prefix,
                        $tag['search'],
                        "$relative: crm-search-display search-name=\"{$tag['search']}\" has no SavedSearch",
                    );
                }
                if ($tag['display'] !== null && !in_array($tag['display'], $names['SearchDisplay'], true)) {
                    self::report(
                        $reporter,
                        $prefix,
                        $tag['display'],
                        "$relative: crm-search-display display-name=\"{$tag['display']}\" has no SearchDisplay",
                    );
                }
            }
        }
    }

    /**
     * Own-looking names must be shipped here (fail); foreign-looking ones may
     * come from a dependency (warn).
     */
    private static function report(Reporter $reporter, ?string $prefix, string $target, string $message): void
    {
        if ($prefix !== null && str_starts_with(strtolower($target), strtolower($prefix))) {
            $reporter->fail("$message — no such record is shipped in this extension");

            return;
        }
        $reporter->warn("$message — target not shipped here; if it comes from another extension, ensure <requires> covers it");
    }

    /**
     * Text-scan the layout for `<crm-search-display ...>` tags. A parser is not
     * worth it: these layouts are Angular HTML fragments, not documents, and
     * the two attributes are always literal strings.
     *
     * @return list<array{search: string|null, display: string|null}>
     */
    private static function searchDisplayTags(string $html): array
    {
        preg_match_all('/<crm-search-display\b[^>]*>/i', $html, $matches);
        $tags = [];
        foreach ($matches[0] as $tag) {
            $search = preg_match('/\bsearch-name\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
            $display = preg_match('/\bdisplay-name\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
            $tags[] = [
                'search' => ($search === null || $search === '') ? null : $search,
                'display' => ($display === null || $display === '') ? null : $display,
            ];
        }

        return $tags;
    }

    private static function shortName(Context $context): ?string
    {
        $info = $context->infoXml();
        $key = $info === null ? '' : (string) ($info['key'] ?? '');
        if ($key === '') {
            return null;
        }
        $parts = explode('.', $key);
        $last = (string) end($parts);

        return $last === '' ? null : $last;
    }

    /**
     * Autoload stub for any CRM_*_ExtensionUtil so `use ... as E; E::ts()`
     * works outside a CiviCRM boot.
     */
    private static function registerExtensionUtilStub(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function (string $class): void {
            if (preg_match('/^CRM_\w+_ExtensionUtil$/', $class) !== 1) {
                return;
            }
            eval(sprintf(
                'class %s {
                    public static function ts($text, $params = []) { return $text; }
                    public static function __callStatic($name, $args) { return $args[0] ?? \'\'; }
                }',
                $class,
            ));
        });
    }
}
