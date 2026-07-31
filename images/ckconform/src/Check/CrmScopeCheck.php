<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * `{ts}` in an extension template that no `{crmScope extensionKey='…'}` covers.
 *
 * The Smarty twin of bare `ts()` in PHP, with the identical silent failure: a
 * `{ts}` block resolves in CIVICRM CORE's translation domain unless a
 * `{crmScope}` names the extension, so the extension's own .po files are never
 * consulted. Nothing errors, nothing warns, no log line appears — the string
 * just renders in English forever, on an install whose l10n/ is complete and
 * whose translators did their job. The phpcs sniff CiviKitchen.I18n.UseExtensionTs
 * closes the PHP half of this hole; the templates had no equivalent.
 *
 * The same applies to the message-template bodies an extension ships inside a
 * `.mgd.php`: they are Smarty too, and a `{ts}` in a mail body is scoped by the
 * same wrapper or by nothing at all.
 *
 * Judged per file. `{crmScope}` is a runtime stack, so a partial rendered by
 * `{include}` from inside a wrapper does inherit the domain — but a partial that
 * relies on that breaks the moment it is included from anywhere else, and
 * wrapping it in its own `{crmScope}` costs nothing and is never wrong. Files
 * are therefore required to stand on their own.
 *
 * A wrapper whose extensionKey is a Smarty variable is accepted: what it
 * resolves to is not statically knowable, and refusing to guess beats guessing
 * wrong. A wrapper that names a *different* extension's key, on the other hand,
 * is exactly the mistake this rule exists for — the fleet's original sighting
 * was an extension that hand-passed a neighbouring key and never noticed.
 */
final class CrmScopeCheck implements Check
{
    public function name(): string
    {
        return 'crm-scope';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $key = $this->extensionKey($context);
        if ($key === null) {
            // No info.xml key: nothing to compare a wrapper against.
            return;
        }

        foreach ($this->sources($context) as $relative) {
            $source = $context->read($relative);
            if ($source === null || !str_contains($source, '{ts')) {
                continue;
            }
            foreach ($this->findings($source, $key) as $reason => $lines) {
                $where = count($lines) === 1
                    ? 'line ' . $lines[0]
                    : 'lines ' . implode(', ', $lines);
                $reporter->fail(
                    "$relative: $where: $reason — {ts} resolves in core's translation domain, "
                    . "so this extension's own .po is never consulted and the string stays untranslated"
                );
            }
        }
    }

    /**
     * Templates the extension owns, plus the `.mgd.php` files that may carry a
     * message-template body. Tests and vendored code are not shipped UI.
     *
     * @return list<string>
     */
    private function sources(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.tpl', '.mgd.php'])
            : $context->findFiles('', ['.tpl', '.mgd.php']);

        return array_values(array_filter($files, static function (string $file): bool {
            if (str_starts_with($file, 'tests/')
                || str_starts_with($file, 'vendor/')
                || str_starts_with($file, 'node_modules/')
            ) {
                return false;
            }

            return str_ends_with($file, '.mgd.php') || str_starts_with($file, 'templates/');
        }));
    }

    private function extensionKey(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        foreach ([(string) $info['key'], (string) ($info->file ?? '')] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Uncovered `{ts}` blocks, grouped by reason so a template with twenty
     * strings and one missing wrapper produces one line, not twenty.
     *
     * @return array<string, list<int>> reason => 1-based line numbers
     */
    private function findings(string $source, string $key): array
    {
        $source = $this->blankOutInertRegions($source);

        /** @var list<array{offset: int, kind: string, key: string|null, dynamic: bool}> $events */
        $events = [];
        foreach ($this->matches('/\{crmScope\b([^}]*)\}/i', $source) as [$offset, $groups]) {
            [$scopeKey, $dynamic] = $this->scopeKey($groups[1] ?? '');
            $events[] = ['offset' => $offset, 'kind' => 'open', 'key' => $scopeKey, 'dynamic' => $dynamic];
        }
        foreach ($this->matches('#\{/crmScope\}#i', $source) as [$offset]) {
            $events[] = ['offset' => $offset, 'kind' => 'close', 'key' => null, 'dynamic' => false];
        }
        foreach ($this->matches('/\{ts(?=[\s}])[^}]*\}/', $source) as [$offset]) {
            $events[] = ['offset' => $offset, 'kind' => 'ts', 'key' => null, 'dynamic' => false];
        }
        usort($events, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        /** @var list<array{key: string|null, dynamic: bool}> $stack */
        $stack = [];
        $findings = [];
        foreach ($events as $event) {
            if ($event['kind'] === 'open') {
                $stack[] = ['key' => $event['key'], 'dynamic' => $event['dynamic']];
                continue;
            }
            if ($event['kind'] === 'close') {
                array_pop($stack);
                continue;
            }

            $reason = $this->reason($stack === [] ? null : $stack[count($stack) - 1], $key);
            if ($reason === null) {
                continue;
            }
            $findings[$reason] ??= [];
            $findings[$reason][] = substr_count($source, "\n", 0, $event['offset']) + 1;
        }

        return $findings;
    }

    /**
     * @param array{key: string|null, dynamic: bool}|null $scope
     */
    private function reason(?array $scope, string $key): ?string
    {
        if ($scope === null) {
            return "no {crmScope extensionKey='$key'} around it";
        }
        if ($scope['dynamic']) {
            return null;
        }
        if ($scope['key'] === null || $scope['key'] === '') {
            return "the enclosing {crmScope} names no extensionKey (expected '$key')";
        }
        if ($scope['key'] !== $key) {
            return "the enclosing {crmScope} names '{$scope['key']}', not this extension's key '$key'";
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: bool} literal key (or null), and whether
     *                                        it is a Smarty expression
     */
    private function scopeKey(string $attributes): array
    {
        if (preg_match('/extensionKey\s*=\s*(?:\'([^\']*)\'|"([^"]*)"|(\S+))/i', $attributes, $match) !== 1) {
            return [null, false];
        }
        if (($match[3] ?? '') !== '') {
            $bare = $match[3];

            return str_contains($bare, '$') ? [null, true] : [$bare, false];
        }
        $quoted = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

        return str_contains($quoted, '$') ? [null, true] : [$quoted, false];
    }

    /**
     * Smarty comments and `{literal}` blocks, replaced by spaces of the same
     * length: their contents are never parsed as tags, and blanking rather than
     * deleting keeps every remaining offset — and so every reported line number
     * — correct.
     */
    private function blankOutInertRegions(string $source): string
    {
        return (string) preg_replace_callback(
            ['/\{\*.*?\*\}/s', '#\{literal\}.*?\{/literal\}#si'],
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? $m[0],
            $source,
        );
    }

    /**
     * @return list<array{0: int, 1: array<int, string>}> offset, capture groups
     */
    private function matches(string $pattern, string $source): array
    {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }
        $found = [];
        foreach ($matches as $set) {
            $groups = [];
            foreach ($set as $index => $capture) {
                $groups[$index] = $capture[0];
            }
            $found[] = [(int) $set[0][1], $groups];
        }

        return $found;
    }
}
