<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Smarty tags in shipped templates that Smarty 5 refuses to compile.
 *
 * CiviCRM is phasing Smarty 5 in (core ships it as packages/smarty5, a thin
 * wrapper around smarty/smarty ^5). Smarty 5 dropped several tags that Smarty 2
 * shipped, and it does not ignore them: the compiler throws
 * Smarty\CompilerException "unknown tag" and the whole page fatals. A template
 * carrying one is not degraded, it is a white screen for the first user who
 * opens it — on the day the install flips to Smarty 5, with nothing having
 * changed in the extension.
 *
 * Only tags that were verified to fail were included. Each was compiled against
 * the Smarty 5 that core actually ships, both with and without core's
 * BCPluginsAdapter, and each threw. Core's plugin directory
 * (CRM/Core/Smarty/plugins/) was checked for a shim under the same name and has
 * none — unlike `|smarty:nodefaults`, which core DOES shim (modifier.smarty.php)
 * and which is therefore not a finding here. Constructs that merely look
 * obsolete but compile fine — `{foreach from=…}`, `{section}`, `{$smarty.now}`,
 * `{$x|@count}`, `{strip}`, `{textformat}`, `{cycle}`, `{counter}`,
 * `{html_options}`, `{math}`, `{eval}`, `{fetch}`, a raw `<?php ?>` — are not
 * findings either, however dated they read.
 *
 * `{literal}` blocks and `{* … *}` comments are inert: their contents never
 * reach the tag compiler (verified — `{literal}{php}…{/php}{/literal}` renders
 * as text under Smarty 5), so a tag inside one is not a finding.
 *
 * A repo that ships its own Smarty plugin under a flagged name — a tracked
 * `function.insert.php`, `block.php.php`, `compiler.popup.php` — has registered
 * that tag itself and is exempt: registerPlugin() makes the tag compile again.
 *
 * warn, not FAIL: an install still on Smarty 4 renders these today, so this is
 * a deadline, not a present-tense breakage.
 *
 * SUPPRESSION. Inline `ckconform-ignore` comments elsewhere in this tool are
 * PHP-token based and cannot work in a .tpl, which is not PHP. The escape here
 * is a Smarty comment on the finding's line or the line above it:
 *
 *     {* ckconform-ignore smarty-compat -- kept for the Smarty 4 branch *}
 *
 * The reason after `--` is mandatory, as everywhere else: a bare marker
 * suppresses nothing and is itself reported, because an unexplained silencer is
 * indistinguishable from a forgotten one.
 *
 * Deliberately out of scope: the Smarty bodies inside `.mgd.php` message
 * templates (PHP string literals, whose escaping this scanner would have to
 * unwind), unregistered-PHP-function modifiers (Smarty 5 rejects those too, but
 * whether a name is registered depends on core's list plus every plugin
 * directory an install has loaded — unknowable from one repo), and anything
 * requiring an actual compile. This is a static scan for tags with a proven
 * verdict, not a Smarty 5 dry run.
 */
final class SmartyCompatCheck implements Check
{
    /**
     * Tag => what to do instead. Every key throws "unknown tag" in Smarty 5.
     *
     * @var array<string, string>
     */
    private const REMOVED_TAGS = [
        'php' => 'move the logic into the Page/Form class and assign() the result',
        'include_php' => 'do the work in PHP and assign() it, or register a Smarty plugin for it',
        'insert' => 'removed with no replacement — assign() the fragment from the page class',
        'popup' => 'removed after Smarty 2 — use a CRM_Core_Resources-loaded tooltip instead',
        'popup_init' => 'removed after Smarty 2 — use a CRM_Core_Resources-loaded tooltip instead',
    ];

    /** @var array<string, true> "file:line" of markers already reported as reasonless. */
    private array $reportedMarkers = [];

    public function name(): string
    {
        return 'smarty-compat';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $registered = $this->locallyRegisteredTags($context);

        foreach ($this->sources($context) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            $scannable = $this->blankOutInertRegions($source);
            $lines = explode("\n", $source);

            foreach (self::REMOVED_TAGS as $tag => $advice) {
                if (in_array($tag, $registered, true)) {
                    continue;
                }
                foreach ($this->offsets($scannable, $tag) as $offset) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    if ($this->isSuppressed($lines, $line, $reporter, $relative)) {
                        continue;
                    }
                    $reporter->warn(
                        "$relative:$line: {{$tag}} was removed in Smarty 5 — the compiler throws "
                        . "\"unknown tag '$tag'\" and the page fatals; $advice"
                    );
                }
            }
        }
    }

    /**
     * Offsets of the tag's OPENING occurrences. A closing `{/php}` is not
     * reported separately: one block is one finding.
     *
     * @return list<int>
     */
    private function offsets(string $source, string $tag): array
    {
        // (?=[\s}]) so {insert …} matches and {inserted} does not. No leading
        // whitespace is allowed after the delimiter because Smarty's auto_literal
        // treats "{ php}" as plain text, not as a tag.
        $pattern = '/\{' . preg_quote($tag, '/') . '(?=[\s}])/';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $found = [];
        foreach ($matches[0] as $match) {
            $found[] = (int) $match[1];
        }

        return $found;
    }

    /**
     * A `{* ckconform-ignore smarty-compat -- reason *}` on the finding's line
     * or the one above it. A marker without a reason silences nothing and is
     * reported in its own right.
     *
     * @param list<string> $lines
     * @param int          $line  1-based
     */
    private function isSuppressed(array $lines, int $line, Reporter $reporter, string $relative): bool
    {
        foreach ([$line, $line - 1] as $candidate) {
            $text = $lines[$candidate - 1] ?? '';
            if (preg_match('/\{\*\s*ckconform-ignore\s+' . preg_quote($this->name(), '/') . '\b(.*?)\*\}/s', $text, $match) !== 1) {
                continue;
            }
            $reason = trim(preg_replace('/^\s*--/', '', trim($match[1])) ?? '');
            if (str_starts_with(trim($match[1]), '--') && $reason !== '') {
                return true;
            }
            if (!isset($this->reportedMarkers["$relative:$candidate"])) {
                $this->reportedMarkers["$relative:$candidate"] = true;
                $reporter->warn(
                    "$relative:$candidate: ckconform-ignore smarty-compat has no reason — "
                    . 'write "-- <why>" after it; as written it suppresses nothing'
                );
            }
        }

        return false;
    }

    /**
     * Tags this repo registers itself, from the Smarty plugin filenames
     * `<type>.<tag>.php` that addPluginsDir() turns into registerPlugin() calls.
     *
     * @return list<string>
     */
    private function locallyRegisteredTags(Context $context): array
    {
        $tags = [];
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php'])
            : $context->findFiles('', ['.php']);
        foreach ($files as $file) {
            if (preg_match('/^(?:function|block|compiler)\.([A-Za-z0-9_]+)\.php$/', basename($file), $match) === 1) {
                $tags[] = $match[1];
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Shipped templates. Tests and vendored code are not rendered on an
     * install, so they cannot fatal for a user.
     *
     * @return list<string>
     */
    private function sources(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.tpl'])
            : $context->findFiles('', ['.tpl']);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !str_starts_with($file, 'tests/')
                && !str_starts_with($file, 'vendor/')
                && !str_starts_with($file, 'node_modules/'),
        ));
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
}
