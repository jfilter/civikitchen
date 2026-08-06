<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Scalar;
use CiviKitchen\Ckconform\Suppressions;

/**
 * A malformed `*.mgd.php` record does not fail at install time in a readable
 * way — it fails inside the managed-entity reconciliation that runs on every
 * enable, upgrade and `System.flush`. A record without `name`/`entity`, without
 * `params.version`, or with a v4 record missing `params.values`, makes
 * CRM_Core_ManagedEntities throw mid-reconcile, which aborts the whole
 * reconciliation — so unrelated managed records of unrelated extensions stop
 * being created too, and the upgrade screen shows a stack trace instead.
 *
 * The subtler failure class is `module` pointing at the wrong extension key:
 * the record is then owned by an extension that never ships it, so the very
 * next reconcile deletes it as orphaned. Config that "disappears after a
 * flush" is this. A missing `module` is fine — the mgd-php mixin fills it in.
 *
 * Two identical identities (module, name, entity) across files are equally
 * silent: the second record wins, the first never exists.
 *
 * Missing `cleanup` on an admin-editable entity is a warning, not a failure:
 * the default is `always`, so uninstalling the extension deletes the record
 * even if an admin has since rewritten it by hand.
 */
final class ManagedEntityMetadataCheck implements Check
{
    private const VERSIONS = [3, 4, '3', '4'];

    private const CLEANUP = ['always', 'never', 'unused'];

    private const UPDATE = ['always', 'never', 'unmodified'];

    /**
     * Entities an admin routinely edits through the UI, where the default
     * cleanup=always turns an uninstall (or a revert) into data loss.
     */
    private const ADMIN_EDITABLE = [
        'Job',
        'SavedSearch',
        'SearchDisplay',
        'MessageTemplate',
        'Navigation',
        'OptionValue',
        'Afform',
    ];

    public function name(): string
    {
        return 'managed-entity-metadata';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle); mgd files live under managed/ by
        // convention but nothing enforces that, so scan the whole tree.
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.mgd.php'])
            : $context->findFiles('', ['.mgd.php']);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register();

        $extensionKey = $context->extensionKey();
        $seen = [];

        foreach ($files as $relative) {
            try {
                $records = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate managed file outside CiviCRM ({$e->getMessage()}) — managed metadata unchecked");
                continue;
            }

            if (!is_array($records)) {
                $reporter->fail("$relative: does not return a list of managed records");
                continue;
            }

            foreach ($records as $index => $record) {
                $label = "$relative record #$index";
                if (!is_array($record)) {
                    $reporter->fail("$label: is not an array");
                    continue;
                }

                $name = $record['name'] ?? null;
                foreach (['name', 'entity', 'params'] as $key) {
                    if (!isset($record[$key])) {
                        $reporter->fail("$label: missing required key '$key'");
                    }
                }
                if (isset($record['name']) && is_string($record['name'])) {
                    $label = "$relative record '{$record['name']}'";
                }

                $params = $record['params'] ?? null;
                if (isset($record['params']) && !is_array($params)) {
                    $reporter->fail("$label: 'params' is not an array");
                    $params = null;
                }
                if (is_array($params)) {
                    $version = $params['version'] ?? null;
                    if (!isset($params['version'])) {
                        $reporter->fail("$label: params has no 'version' — the API version is not optional");
                    } elseif (!in_array($version, self::VERSIONS, true)) {
                        $reporter->fail("$label: params version '" . Scalar::describe($version) . "' is not 3 or 4");
                    } elseif ((int) $version === 4 && !isset($params['values'])) {
                        $reporter->fail("$label: APIv4 params without 'values' — nothing would be written");
                    } elseif ((int) $version === 4 && !is_array($params['values'])) {
                        $reporter->fail("$label: APIv4 params 'values' is not an array");
                    } elseif ((int) $version === 3 && !$this->fileSuppressed($context, $relative)) {
                        // Records are evaluated via require, so there is no line
                        // to anchor an inline ignore to — the escape is the
                        // file-wide `ckconform-ignore-file managed-entity-metadata`.
                        $reporter->warn("$label: managed record uses APIv3 — prefer version 4 with 'values' when the entity supports APIv4 save/update on the minimum supported core; otherwise document the compatibility exception");
                    }
                }

                if (isset($record['cleanup']) && !in_array($record['cleanup'], self::CLEANUP, true)) {
                    $reporter->fail("$label: cleanup '" . Scalar::describe($record['cleanup']) . "' is not one of always|never|unused");
                }
                if (isset($record['update']) && !in_array($record['update'], self::UPDATE, true)) {
                    $reporter->fail("$label: update '" . Scalar::describe($record['update']) . "' is not one of always|never|unmodified");
                }

                // A missing module is filled in by the mgd-php mixin; a wrong
                // one makes the next reconcile delete the record as orphaned.
                $module = $record['module'] ?? null;
                if (isset($record['module']) && $extensionKey !== null && $module !== $extensionKey) {
                    $reporter->fail("$label: module '" . Scalar::describe($module) . "' is not this extension's key '$extensionKey' — reconciliation will treat the record as orphaned and delete it");
                }

                if (!isset($record['cleanup'])
                    && is_string($record['entity'] ?? null)
                    && in_array($record['entity'], self::ADMIN_EDITABLE, true)
                ) {
                    $reporter->warn("$label: entity {$record['entity']} is admin-editable but no 'cleanup' is set — the default 'always' discards admin changes on uninstall");
                }

                if (is_string($name) && is_string($record['entity'] ?? null)) {
                    $identity = ($module ?? $extensionKey ?? '') . "\0" . $name . "\0" . $record['entity'];
                    if (isset($seen[$identity])) {
                        $reporter->fail("$label: duplicate managed identity (module, name, entity) — also defined in {$seen[$identity]}; only the last record survives");
                    } else {
                        $seen[$identity] = $relative;
                    }
                }
            }
        }
    }

    /** @var array<string, bool> */
    private array $fileSuppression = [];

    private function fileSuppressed(Context $context, string $relative): bool
    {
        return $this->fileSuppression[$relative] ??= Suppressions::of(
            (string) $context->read($relative)
        )->suppressed($this->name(), 0);
    }

}
