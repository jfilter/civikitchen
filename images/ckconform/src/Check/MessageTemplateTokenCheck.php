<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Own tokens in a message template with no provider, or in core's namespace.
 *
 * Two failure modes, both silent, both lived through in production:
 *
 * 1. A template uses `{myext.something}` and the extension registers no tokens
 *    at all. The token is not an error and not a warning — the mail simply goes
 *    out with the literal braces or with nothing, depending on the renderer.
 *
 * 2. Worse: an extension registers its own tokens *under* `contact.` (the
 *    namespace that looks natural for a per-recipient value). Core owns that
 *    namespace and overwrites every contact token it does not recognise with
 *    the EMPTY string, so the mail is silently blank in exactly that spot. The
 *    fix is an own namespace (`{myext.rufname}`), and there is no runtime signal
 *    that you need it.
 *
 * Deliberately conservative: a token whose namespace does not resemble the
 * extension only warns, because a template may legitimately reference a
 * namespace a dependency provides. Filter syntax (`{contact.x|boolean}`) is
 * normal and never reported.
 */
final class MessageTemplateTokenCheck implements Check
{
    /**
     * Namespaces the framework and the bundled extensions provide. No
     * registration duty for these.
     *
     * @var list<string>
     */
    private const CORE_NAMESPACES = [
        'contact', 'contribution', 'domain', 'mailing', 'action', 'event',
        'participant', 'membership', 'activity', 'case', 'user', 'import',
        'recur', 'contributionRecur', 'contribution_recur', 'pledge', 'grant',
        'petition', 'eventcart', 'financialType', 'lineItem', 'membershipType',
        'participantRole', 'smarty', 'resourceUrls',
    ];

    /**
     * Text that means "this repo registers tokens somewhere". Presence is
     * enough — proving that a given token name comes out of a TokenProcessor
     * subscriber would need to execute it.
     *
     * @var list<string>
     */
    private const REGISTRATION_MARKERS = [
        '_civicrm_tokens',
        'CRM_Utils_Hook::tokenValues',
        '_civicrm_tokenValues',
        'new \\Civi\\Token\\',
        'new Civi\\Token\\',
        'Civi\\Token\\TokenProcessor',
        'TokenProcessor',
        'evaluateToken',
        'addToken(',
        'registerToken',
        'TokenValueEvent',
        'TokenRegisterEvent',
        'AbstractTokenSubscriber',
        'entityToken',
    ];

    public function name(): string
    {
        return 'message-template-token';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo() ? $context->trackedUnder('') : $context->findFiles('');
        if ($files === []) {
            return;
        }

        $shortnames = ExtensionNamespace::all($context);
        $registers = $this->registersTokens($context, $files);

        $this->reportContactNamespaceSquatting($context, $files, $shortnames, $reporter);

        /** @var array<string, list<string>> $namespaces namespace => files */
        $namespaces = [];
        foreach ($this->templateFiles($context, $files) as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach ($this->tokenNamespaces($contents) as $namespace) {
                $namespaces[$namespace] ??= [];
                if (!in_array($file, $namespaces[$namespace], true)) {
                    $namespaces[$namespace][] = $file;
                }
            }
        }
        ksort($namespaces);

        if ($registers) {
            return;
        }

        foreach ($namespaces as $namespace => $where) {
            if ($this->isCoreNamespace($namespace)) {
                continue;
            }
            $origin = implode(', ', array_slice($where, 0, 3));
            $message = "$origin: token namespace '{$namespace}.' is used but this extension registers "
                . 'no tokens (no hook_civicrm_tokens, no TokenProcessor code)';
            if ($this->looksLikeOwnNamespace($namespace, $shortnames)) {
                $reporter->fail("$message — '{$namespace}.' is this extension's own namespace, so nothing provides it");
            } else {
                $reporter->warn("$message — fine if a dependency provides '{$namespace}.'");
            }
        }
    }

    private function isCoreNamespace(string $namespace): bool
    {
        foreach (self::CORE_NAMESPACES as $core) {
            if (strcasecmp($namespace, $core) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $shortnames
     */
    private function looksLikeOwnNamespace(string $namespace, array $shortnames): bool
    {
        $needle = strtolower($namespace);
        foreach ($shortnames as $shortname) {
            if ($needle === $shortname
                || str_contains($needle, $shortname)
                || str_contains($shortname, $needle)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$tokens['contact']['myext_foo'] = …` — an own value smuggled into the
     * namespace core clobbers. Only reported when the key carries the extension
     * shortname, because deciding "is this a core contact field" from a static
     * list would misfire on custom fields.
     *
     * @param list<string> $files
     * @param list<string> $shortnames
     */
    private function reportContactNamespaceSquatting(
        Context $context,
        array $files,
        array $shortnames,
        Reporter $reporter,
    ): void {
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'contact')) {
                continue;
            }
            if (preg_match_all(
                '/\$tokens\s*\[\s*([\'"])contact\1\s*\]\s*\[\s*([\'"])(.+?)\2\s*\]/s',
                $contents,
                $matches,
            ) === 0) {
                continue;
            }
            foreach ($matches[3] as $key) {
                if (!$this->looksLikeOwnNamespace($key, $shortnames)) {
                    continue;
                }
                $reporter->warn(
                    "$file: registers '$key' under the contact.* namespace — custom tokens under "
                    . "contact.* get clobbered by core with '' — use an own namespace",
                );
            }
        }
    }

    /**
     * @param  list<string> $files
     */
    private function registersTokens(Context $context, array $files): bool
    {
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach (self::REGISTRATION_MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Files that carry mail copy: anything under a template-ish directory
     * (msg_templates, msg*, templates, xml/templates), plus any *.mgd.php that
     * actually ships a message body — which is how a modern extension bundles a
     * MessageTemplate. Pragmatic on purpose: Smarty screen variables are
     * `{$dollar}` and control tags carry whitespace, so neither reaches the
     * token pattern.
     *
     * @param  list<string> $files
     * @return list<string>
     */
    private function templateFiles(Context $context, array $files): array
    {
        $found = [];
        foreach ($files as $file) {
            $isMsgPath = preg_match('#(^|/)(msg_templates|msg|templates|xml/templates)/#', $file) === 1;
            $isMsgDir = preg_match('#(^|/)msg[^/]*/#', $file) === 1;

            if (str_ends_with($file, '.mgd.php')) {
                $contents = $context->read($file);
                if ($contents !== null
                    && (str_contains($contents, 'msg_text') || str_contains($contents, 'msg_html'))
                ) {
                    $found[] = $file;
                }
                continue;
            }

            if (!$isMsgPath && !$isMsgDir) {
                continue;
            }
            foreach (['.tpl', '.html', '.txt'] as $extension) {
                if (str_ends_with($file, $extension)) {
                    $found[] = $file;
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Distinct namespaces of `{ns.token}` occurrences. Smarty control tags
     * ({if …}, {foreach …}) never match, because the pattern demands
     * exactly one dot-separated identifier pair and no whitespace.
     *
     * @return list<string>
     */
    private function tokenNamespaces(string $contents): array
    {
        $found = [];
        if (preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z0-9_:]+)\}/',
            $contents,
            $matches,
        ) > 0) {
            foreach ($matches[1] as $namespace) {
                $found[$namespace] = true;
            }
        }

        return array_keys($found);
    }
}
