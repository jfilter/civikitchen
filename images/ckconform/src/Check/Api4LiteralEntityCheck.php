<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A procedural civicrm_api4('Foo', ...) call to one of this extension's own
 * entities that does not exist.
 *
 * Api4EntityCheck catches the object form: \Civi\Api4\VoyageTypo::get() fails
 * because VoyageTypo is in neither core nor this extension. But the procedural
 * form takes the entity as a string — civicrm_api4('VoyageTypo', 'get', ...) —
 * and no static analyser resolves it, so a typo or a half-finished rename
 * fatals only at runtime, on whichever call path happens to hit it.
 *
 * The trap is telling a typo from a legitimate call to ANOTHER extension's
 * entity. voyage calls civicrm_api4('CiviRulesRule', ...) constantly; that
 * entity lives in the civirules extension, not core and not voyage, and a check
 * that flagged it would be wrong. So this stays inside the family it can be sure
 * about: a literal that shares its leading word with an entity this extension
 * defines (VoyageState alongside a local Voyage/VoyageLog) is one of ours, and
 * if we do not define it, it is a mistake. Core entities and other extensions'
 * entities share no leading word with ours and are left alone.
 */
final class Api4LiteralEntityCheck implements Check
{
    private const SKIP = ['vendor/', 'node_modules/', 'dist/', 'build/'];

    public function name(): string
    {
        return 'api4-literal-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $local = $this->localEntities($context);
        if ($local === []) {
            return;
        }
        $family = [];
        foreach ($local as $entity) {
            $family[$this->leadingWord($entity)] = true;
        }

        $dangling = [];
        foreach ($context->tracked('*.php') as $file) {
            if ($this->skipped($file)) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null || !str_contains($source, 'civicrm_api4')) {
                continue;
            }
            foreach ($this->calledEntities($source) as $entity) {
                if (in_array($entity, $local, true)) {
                    continue;
                }
                if (isset($family[$this->leadingWord($entity)])) {
                    $dangling[$entity][$file] = true;
                }
            }
        }

        if ($dangling === []) {
            $reporter->ok('every civicrm_api4() call to an own entity resolves');

            return;
        }

        $parts = [];
        foreach ($dangling as $entity => $files) {
            $parts[] = $entity . ' (' . implode(', ', array_keys($files)) . ')';
        }
        $reporter->fail(
            'civicrm_api4() names own entities that are not defined in Civi/Api4: '
            . implode('; ', $parts) . ' — the call fatals at runtime'
        );
    }

    /**
     * Entity names this extension defines, from Civi/Api4/<Name>.php.
     *
     * @return list<string>
     */
    private function localEntities(Context $context): array
    {
        $entities = [];
        foreach ($context->trackedFiles() as $file) {
            if (preg_match('#(?:^|/)Civi/Api4/([A-Z][A-Za-z0-9_]*)\.php$#', $file, $match) === 1) {
                $entities[] = $match[1];
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * Entity names in the first argument of a civicrm_api4() call. Comments are
     * stripped first, so an example in a docblock is not read as a call.
     *
     * @return list<string>
     */
    private function calledEntities(string $source): array
    {
        $code = $this->withoutComments($source);
        preg_match_all(
            '/civicrm_api4\s*\(\s*[\'"]([A-Z][A-Za-z0-9_]*)[\'"]/',
            $code,
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    private function leadingWord(string $name): string
    {
        return preg_match('/^[A-Z][a-z0-9]*/', $name, $match) === 1 ? $match[0] : $name;
    }

    private function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function skipped(string $file): bool
    {
        foreach (self::SKIP as $directory) {
            if (str_starts_with($file, $directory) || str_contains($file, '/' . $directory)) {
                return true;
            }
        }

        return false;
    }
}
