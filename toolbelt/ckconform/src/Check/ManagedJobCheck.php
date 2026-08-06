<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Scalar;

/**
 * A managed Job is scheduled config that nobody looks at again: it either runs
 * silently every hour or it silently never runs. The failure classes here all
 * look fine in the file.
 *
 * A Job without api_entity/api_action is created happily and then throws on
 * every cron pass, so the scheduled-job log fills with errors nobody reads. An
 * unknown run_frequency is not in the option list, so the job never matches a
 * cron window at all. A non-string `parameters` breaks Civi's line-based
 * parser (it splits the value on newlines and `=`), so the job runs with
 * missing arguments — a nightly sync that silently syncs nothing.
 *
 * The reactivation trap is the expensive one: a managed Job with
 * `is_active => TRUE` and update `always` (the default) is re-enabled by every
 * upgrade and reconcile, so a job an admin deliberately switched off comes back
 * — and starts sending mail or writing to a remote system again.
 *
 * A Job pointing at an API of this extension that the repo does not ship is a
 * warning: it may come from a dependency, but far more often it is a rename
 * that the mgd file did not follow.
 */
final class ManagedJobCheck implements Check
{
    private const FREQUENCIES = ['Always', 'Hourly', 'Daily', 'Weekly', 'Monthly', 'Quarter', 'Yearly'];

    public function name(): string
    {
        return 'managed-job';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $repoFiles = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        $prefix = $context->shortName();

        foreach (ManagedFiles::records($context, $reporter, 'managed jobs unchecked') as [$relative, $records]) {
            foreach ($records as $index => $record) {
                if (!is_array($record) || ($record['entity'] ?? null) !== 'Job') {
                    continue;
                }
                $params = $record['params'] ?? null;
                if (!is_array($params)) {
                    continue;
                }
                $label = is_string($record['name'] ?? null)
                    ? "$relative job '{$record['name']}'"
                    : "$relative job #$index";

                // v4 puts the record under 'values'; v3 spreads it into params.
                $values = ((int) ($params['version'] ?? 4) === 4)
                    ? (is_array($params['values'] ?? null) ? $params['values'] : null)
                    : $params;
                if ($values === null) {
                    continue;
                }

                foreach (['api_entity', 'api_action'] as $key) {
                    if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
                        $reporter->fail("$label: no $key — the job throws on every cron pass");
                    }
                }

                if (isset($values['run_frequency'])
                    && !in_array($values['run_frequency'], self::FREQUENCIES, true)
                ) {
                    $reporter->fail("$label: run_frequency '" . Scalar::describe($values['run_frequency']) . "' is not one of " . implode('|', self::FREQUENCIES));
                }

                if (isset($values['parameters']) && !is_string($values['parameters'])) {
                    $reporter->fail("$label: 'parameters' is " . get_debug_type($values['parameters']) . ", not a string — Civi parses it line by line, so the job would run without its arguments");
                }

                $update = $record['update'] ?? null;
                if (($update === null || $update === 'always')
                    && in_array($values['is_active'] ?? null, [true, 1, '1'], true)
                ) {
                    $reporter->warn("$label: is_active is set and update is '" . ($update ?? 'always (default)') . "' — an upgrade/reconcile re-enables a job an admin disabled; set update => 'never' or omit is_active");
                }

                $entity = $values['api_entity'] ?? null;
                if (is_string($entity) && $entity !== '' && $prefix !== null
                    && str_starts_with(strtolower($entity), strtolower($prefix))
                    && !self::shipsApi($repoFiles, $entity)
                ) {
                    $reporter->warn("$label: api_entity '$entity' looks like this extension's own API but neither Civi/Api4/$entity.php nor api/v3/$entity*.php is in the repo");
                }
            }
        }
    }

    /**
     * @param list<string> $repoFiles
     */
    private static function shipsApi(array $repoFiles, string $entity): bool
    {
        foreach ($repoFiles as $file) {
            if (str_ends_with($file, "Civi/Api4/$entity.php")) {
                return true;
            }
            if (preg_match('#(^|/)api/v3/' . preg_quote($entity, '#') . '[^/]*\.php$#', $file) === 1) {
                return true;
            }
        }

        return false;
    }

}
