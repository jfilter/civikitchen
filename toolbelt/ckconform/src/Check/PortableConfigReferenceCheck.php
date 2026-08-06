<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Shipped configuration that names a site-specific numeric ID works on exactly
 * one instance: the one it was exported from. `custom_42` is the column name of
 * a custom field whose ID differs on every other database, and
 * `custom_group_id: 7` points at whatever group happens to be seventh there.
 * The managed record installs without complaint and the SearchKit display or
 * Afform then queries a field that belongs to someone else, or to nothing —
 * an empty column, a "field not found" on save, or silently wrong data.
 * Portable forms use the `group_name.field_name` form, or an `:name` suffix.
 *
 * The second failure class is double-encoded payloads. `api_params` and friends
 * are stored as structured arrays; a PHP-serialized string (`a:3:{`) or a JSON
 * string that itself contains JSON was produced by exporting an already-encoded
 * column, and CiviCRM will hand that string to code expecting an array.
 *
 * mgd files are `return [...]` PHP with at most an ExtensionUtil dependency, so
 * they are evaluated with a stubbed ExtensionUtil; a file that throws anyway is
 * reported as a warning rather than silently skipped. Afform files are scanned
 * as text — the JSON half structurally, the HTML half by attribute pattern.
 *
 * Plain numbers are deliberately not flagged: `domain_id: 1`, weights and
 * `is_active: 1` are legitimate, so only the specific ID-bearing keys and the
 * `custom_\d+` shape are reported.
 */
final class PortableConfigReferenceCheck implements Check
{
    /** Keys whose numeric value is a site-specific row ID. */
    private const ID_KEYS = ['custom_group_id', 'custom_field_id', 'option_group_id'];

    /** Fields CiviCRM stores as structured arrays, never as encoded strings. */
    private const STRUCTURED_KEYS = ['api_params', 'settings', 'layout', 'values'];

    public function name(): string
    {
        return 'portable-config-reference';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $this->checkManaged($context, $reporter);
        $this->checkAfforms($context, $reporter);
    }

    private function checkManaged(Context $context, Reporter $reporter): void
    {
        foreach (ManagedFiles::records($context, $reporter, 'portable-reference check skipped') as [$relative, $records]) {
            $this->walk($records, $relative, '', false, $reporter);
        }
    }

    /**
     * @param array<mixed> $node
     */
    private function walk(
        array $node,
        string $relative,
        string $path,
        bool $structured,
        Reporter $reporter,
    ): void {
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            $inStructured = $structured
                || (is_string($key) && in_array($key, self::STRUCTURED_KEYS, true));

            if (is_array($value)) {
                $this->walk($value, $relative, $here, $inStructured, $reporter);
                continue;
            }

            if (is_string($key)
                && in_array($key, self::ID_KEYS, true)
                && (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1))
            ) {
                $reporter->fail("$relative: $here = $value references a row by numeric ID — use the ':name' suffix or a name, or the record points at a different group on every other instance");
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            if (preg_match('/^custom_\d+$/', $value) === 1) {
                $reporter->fail("$relative: $here = '$value' names a custom field by numeric ID — use 'group_name.field_name', which is the same on every instance");
                continue;
            }

            if ($inStructured && preg_match('/^a:\d+:\{/', $value) === 1) {
                $reporter->fail("$relative: $here is a PHP-serialized string inside a structured field — double-encoded config, CiviCRM expects an array here");
                continue;
            }

            if (self::isDoubleEncodedJson($value)) {
                $reporter->warn("$relative: $here looks double JSON-encoded (a JSON string whose content is itself JSON) — probably exported from an already-encoded column");
            }
        }
    }

    /**
     * A string that json_decodes to another string which is itself a valid JSON
     * array/object — i.e. the value was encoded twice.
     */
    private static function isDoubleEncodedJson(string $value): bool
    {
        if (preg_match('/^"\s*[\[{]/', $value) !== 1) {
            return false;
        }
        $once = json_decode($value, true);
        if (!is_string($once)) {
            return false;
        }

        return is_array(json_decode($once, true));
    }

    private function checkAfforms(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('ang', ['.aff.json', '.aff.html'])
            : $context->findFiles('ang', ['.aff.json', '.aff.html']);

        foreach ($files as $relative) {
            $raw = $context->read($relative);
            if ($raw === null) {
                continue;
            }

            if (str_ends_with($relative, '.aff.json')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->walk($decoded, $relative, '', false, $reporter);
                }
                continue;
            }

            if (preg_match_all('/name\s*=\s*["\'](custom_\d+)["\']/', $raw, $matches) >= 1) {
                foreach (array_unique($matches[1]) as $field) {
                    $reporter->warn("$relative: af-field name=\"$field\" names a custom field by numeric ID — portable forms use 'group_name.field_name'");
                }
            }
        }
    }

}
