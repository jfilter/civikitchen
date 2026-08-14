<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A permission string that is never defined anywhere.
 *
 * CRM_Core_Permission::check() on an unknown string is not an error — it is
 * simply false, forever. A menu item guarded by 'acces CiviFoo' or
 * 'administer civifoo' (when the extension defines 'administer CiviFoo')
 * therefore becomes a silent always-no: the page 404s, the API action refuses,
 * and nothing anywhere says why. Nobody notices until a user complains that
 * they cannot see a screen that was supposed to be theirs.
 *
 * The check closes the loop over the strings the repo *uses* (menu XML,
 * ::check() literals, mgd/API 'permission' specs, aff.json) against the ones it
 * *defines* (hook_civicrm_permission) plus an embedded list of core
 * permissions. A near-miss on an own permission is a provable typo and fails; a
 * completely unknown string may legitimately come from a dependency and only
 * warns. Defined-but-unused is not reported: a permission an extension only
 * hands to ACLs or to a downstream repo is perfectly normal.
 */
final class PermissionClosureCheck implements Check
{
    /**
     * Core permissions, generously. Wrong in the "too small" direction only
     * produces warnings, never a false FAIL — a FAIL additionally requires a
     * near-identical *own* permission, which core strings never have.
     *
     * @var list<string>
     */
    private const CORE_PERMISSIONS = [
        'access CiviCRM', 'administer CiviCRM', 'administer CiviCRM data',
        'administer CiviCRM system', 'edit all contacts', 'view all contacts',
        'add contacts', 'delete contacts', 'access deleted contacts',
        'merge duplicate contacts', 'edit groups', 'manage tags', 'import contacts',
        'access CiviContribute', 'edit contributions', 'delete in CiviContribute',
        'access CiviMail', 'delete in CiviMail', 'view public CiviMail content',
        'access CiviMember', 'edit memberships', 'delete in CiviMember',
        'access CiviEvent', 'edit event participants', 'register for events',
        'view event info', 'delete in CiviEvent', 'access CiviReport',
        'administer reserved reports', 'save Report Criteria', 'access Report Criteria',
        'access CiviCase', 'add cases', 'delete in CiviCase', 'administer CiviCase',
        'access my cases and activities', 'access all cases and activities',
        'administer CiviCampaign', 'manage campaign', 'sign CiviCRM Petition',
        'gotv campaign contacts', 'interview campaign contacts',
        'release campaign contacts', 'reserve campaign contacts',
        'view all activities', 'delete activities', 'access all custom data',
        'access uploaded files', 'profile listings and forms', 'profile listings',
        'profile create', 'profile edit', 'profile view',
        'close all manual batches', 'create manual batch', 'edit all manual batches',
        'view all manual batches', 'export all manual batches',
        'delete all manual batches', 'view all notes', 'add contact notes',
        'view my contact', 'edit my contact', 'edit message templates',
        'edit system workflow message templates', 'edit user-driven message templates',
        'render templates', 'administer payment processors',
        'all CiviCRM permissions and ACLs', 'skip IDS check', 'access AJAX API',
        'edit api keys', 'edit own api key', 'view my invoices',
        'make online contributions', 'view debug output', 'manage queues',
        'administer queues', 'translate CiviCRM', 'import SQL datasource',
        'force merge duplicate contacts', 'administer dedupe rules',
        'manage tag groups', 'administer reserved groups', 'administer reserved tags',
        'edit inbound email basic information',
        'edit inbound email basic information and content',
        'access contact reference fields', 'view own manual batches',
        'edit own manual batches', 'delete own manual batches',
        'export own manual batches', 'reopen own manual batches',
        'reopen all manual batches', 'close own manual batches',
        'refund contributions', 'edit contact summary layouts', 'administer afform',
        'administer search_kit', 'administer API keys', 'authenticate with password',
        'authenticate with api key', "generate any user's JWT",
        "validate any user's credentials",
    ];

    /**
     * Not permissions at all but the sentinels CiviCRM accepts in the same slot.
     *
     * @var list<string>
     */
    private const PSEUDO_PERMISSIONS = ['*always allow*', '*allow*', '\*always allow\*', '1', '0'];

    public function name(): string
    {
        return 'permission-closure';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo() ? $context->trackedUnder('') : $context->findFiles('');
        if ($files === []) {
            return;
        }

        $defined = $this->definedPermissions($context, $files);
        /** @var array<string, list<string>> $used permission => files */
        $used = $this->usedPermissions($context, $files);

        foreach ($used as $permission => $where) {
            if ($this->isKnown($permission, $defined)) {
                continue;
            }

            $near = $this->nearestDefined($permission, $defined);
            $origin = implode(', ', array_slice($where, 0, 3));
            if ($near !== null) {
                $reporter->fail(
                    "$origin: permission '$permission' is never defined, but this extension defines "
                    . "'$near' — a typo makes the check a silent always-no",
                );
                continue;
            }

            $reporter->warn(
                "$origin: permission '$permission' is neither a known core permission nor defined by "
                . 'this extension — fine if a dependency defines it, a silent always-no otherwise',
            );
        }
    }

    /**
     * @param list<string> $defined
     */
    private function isKnown(string $permission, array $defined): bool
    {
        return in_array($permission, self::PSEUDO_PERMISSIONS, true)
            || in_array($permission, self::CORE_PERMISSIONS, true)
            || in_array($permission, $defined, true);
    }

    /**
     * A defined permission that the used string was almost certainly meant to
     * be. Case-only drift counts however long the string is; a Levenshtein
     * distance of up to 3 needs the string to be long enough that three edits
     * cannot turn it into something genuinely different.
     *
     * @param  list<string> $defined
     */
    private function nearestDefined(string $permission, array $defined): ?string
    {
        foreach ($defined as $candidate) {
            if (strcasecmp($permission, $candidate) === 0) {
                return $candidate;
            }
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($defined as $candidate) {
            if (strlen($candidate) < 8) {
                continue;
            }
            $distance = levenshtein(strtolower($permission), strtolower($candidate));
            if ($distance > 0 && $distance <= 3 && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Permissions this extension declares: hook_civicrm_permission assignments
     * and the array literal a hook may return, plus APIv4 permission providers
     * where the string is greppable.
     *
     * @param  list<string> $files
     * @return list<string>
     */
    private function definedPermissions(Context $context, array $files): array
    {
        $defined = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }

            // $permissions['administer CiviFoo'] = ... — the canonical hook body.
            if (preg_match_all(
                '/\$permissions\s*\[\s*([\'"])(.+?)\1\s*\]/s',
                $contents,
                $matches,
            ) > 0) {
                foreach ($matches[2] as $permission) {
                    $defined[] = $permission;
                }
            }

            // A hook or provider that returns the whole map at once. Scanned
            // only inside the function body: a .php file with a dozen hooks in
            // it would otherwise donate every unrelated string with a space to
            // the definition set, and an over-wide definition set is what turns
            // a warning into a false FAIL.
            foreach ([
                '/function\s+\w*_civicrm_permission\s*\(/i',
                '/function\s+getPermissions\s*\(/i',
            ] as $signature) {
                $body = $this->functionBody($contents, $signature);
                if ($body === null) {
                    continue;
                }
                foreach ($this->arrayKeyLiterals($body) as $permission) {
                    $defined[] = $permission;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $defined,
            static fn (string $p): bool => $p !== '' && !str_contains($p, '$'),
        )));
    }

    /**
     * The `{ … }` body of the first function matching $signature, by brace
     * counting. Crude but adequate: strings containing braces only ever make
     * the slice longer, never shorter, and a missing closing brace yields the
     * rest of the file rather than nothing.
     */
    private function functionBody(string $contents, string $signature): ?string
    {
        if (preg_match($signature, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = strpos($contents, '{', (int) $match[0][1]);
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $length = strlen($contents);
        for ($i = $start; $i < $length; $i++) {
            if ($contents[$i] === '{') {
                $depth++;
            } elseif ($contents[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, $start, $i - $start + 1);
                }
            }
        }

        return substr($contents, $start);
    }

    /**
     * `'administer CiviFoo' => [...]` keys — only strings that look like a
     * permission (a space or a CiviCRM-ish word), so ordinary config arrays in
     * the same file do not inflate the definition set.
     *
     * @return list<string>
     */
    private function arrayKeyLiterals(string $contents): array
    {
        $found = [];
        if (preg_match_all('/([\'"])([^\'"\n]{4,})\1\s*=>/', $contents, $matches) > 0) {
            foreach ($matches[2] as $literal) {
                if (str_contains($literal, ' ')) {
                    $found[] = $literal;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string> $files
     * @return array<string, list<string>>
     */
    private function usedPermissions(Context $context, array $files): array
    {
        $used = [];
        foreach ($files as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }

            $permissions = [];
            if (preg_match('#(^|/)xml/Menu/[^/]+\.xml$#', $file) === 1) {
                $permissions = $this->fromMenuXml($contents);
            } elseif (str_ends_with($file, '.php')) {
                $permissions = $this->fromPhp($contents);
            } elseif (str_ends_with($file, '.aff.json')) {
                $permissions = $this->fromAffJson($contents);
            }

            foreach ($permissions as $permission) {
                // CiviCRM accepts comma/semicolon permission expressions in
                // more than menu XML (notably API action metadata). Closure is
                // about every leaf permission, independent of AND/OR shape.
                foreach (preg_split('/[;,]/', $permission) ?: [] as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $used[$part] ??= [];
                    if (!in_array($file, $used[$part], true)) {
                        $used[$part][] = $file;
                    }
                }
            }
        }

        ksort($used);

        return $used;
    }

    /**
     * <access_arguments> holds a list: ';' is AND, ',' is OR, and both may be
     * mixed. For closure purposes every element has to exist, so the structure
     * does not matter — only the strings do.
     *
     * @return list<string>
     */
    private function fromMenuXml(string $contents): array
    {
        $found = [];
        if (preg_match_all('#<access_arguments>(.*?)</access_arguments>#s', $contents, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                foreach (preg_split('/[;,]/', $raw) ?: [] as $part) {
                    $part = trim(html_entity_decode($part));
                    if ($part !== '') {
                        $found[] = $part;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * ::check() literals and 'permission' => … specs (mgd records, APIv4
     * actions, Afform metadata in PHP). Only literals: a variable or a constant
     * cannot be resolved statically, and guessing would produce noise.
     *
     * @return list<string>
     */
    private function fromPhp(string $contents): array
    {
        $found = [];

        if (preg_match_all(
            '/CRM_Core_Permission::check\s*\(\s*([\'"])(.+?)\1/s',
            $contents,
            $matches,
        ) > 0) {
            foreach ($matches[2] as $permission) {
                $found[] = $permission;
            }
        }

        // 'permission' => 'x'  and  'permission' => ['x', 'y']
        if (preg_match_all(
            '/([\'"])permission\1\s*=>\s*(\[[^\]]*\]|array\s*\([^)]*\)|([\'"]).*?\3)/s',
            $contents,
            $matches,
        ) > 0) {
            foreach ($matches[2] as $value) {
                if (preg_match_all('/([\'"])(.*?)\1/s', $value, $inner) > 0) {
                    foreach ($inner[2] as $permission) {
                        $found[] = $permission;
                    }
                }
            }
        }

        return array_values(array_filter(
            array_map('trim', $found),
            static fn (string $p): bool => $p !== '' && !str_contains($p, '$'),
        ));
    }

    /**
     * @return list<string>
     */
    private function fromAffJson(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['permission'])) {
            return [];
        }
        $value = $decoded['permission'];
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $values),
            static fn (string $p): bool => $p !== '',
        ));
    }
}
