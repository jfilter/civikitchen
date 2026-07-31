<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A shipped `l10n/` whose compiled catalogs do not match its sources.
 *
 * Gettext is a two-file arrangement and only one of the two is ever read: at
 * runtime CiviCRM loads the binary `.mo`, never the `.po` a translator edits.
 * So the whole class of "translated, but not really" bugs is invisible — the
 * .po is full, the UI is English, and nothing anywhere says why:
 *
 * 1. A `.po` with no `.mo` beside it. Every string in it is inert.
 * 2. A `.mo` that predates the `.po`. The strings added since the last
 *    `msgfmt` run are the ones nobody sees, which is to say the newest ones.
 *
 * Freshness is decided by CONTENT, not by timestamps: git does not preserve
 * mtimes, so a fresh clone — every CI run, every deploy — gives every file the
 * same checkout time, and an mtime comparison would be a coin flip that usually
 * reads clean. Each translated msgid of the .po is instead looked up in the .mo.
 *
 * Third, at warn level: string literals handed to `E::ts()` or `{ts}` that
 * appear in no catalog at all. Those are strings added after the last
 * extraction run — translatable in principle, untranslatable in practice. A
 * `ts()` whose first argument is not a literal is warned about for the same
 * reason: no extractor can see it, so it never reaches a .po either.
 *
 * Shipping translations is optional and an extension without `l10n/` is none of
 * this rule's business — it stays completely silent. Shipping *broken*
 * translations is the bug.
 */
final class TranslationCatalogCheck implements Check
{
    /** How many offending strings to name before the message gets unhelpful. */
    private const EXAMPLES = 3;

    public function name(): string
    {
        return 'translation-catalog';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $catalogs = $this->catalogs($context);
        if ($catalogs === []) {
            return;
        }

        /** @var array<string, list<string>> $msgids catalog => every msgid in it */
        $msgids = [];
        foreach ($catalogs as $catalog) {
            $source = $context->read($catalog);
            if ($source === null) {
                continue;
            }
            $entries = $this->parsePo($source);
            $msgids[$catalog] = array_keys($entries);

            if (str_ends_with($catalog, '.pot')) {
                // A .pot is the extraction template, not a translation: it has
                // no target language and nothing to compile.
                continue;
            }
            $this->judgeCompiledCatalog($context, $reporter, $catalog, $entries);
        }

        $this->judgeSourceStrings($context, $reporter, $msgids);
    }

    /**
     * @param array<string, bool> $entries msgid => is translated
     */
    private function judgeCompiledCatalog(
        Context $context,
        Reporter $reporter,
        string $catalog,
        array $entries,
    ): void {
        $compiled = substr($catalog, 0, -3) . '.mo';
        if (!$this->ships($context, $compiled)) {
            $reporter->fail(
                "$catalog: no compiled $compiled beside it — CiviCRM reads only the .mo at runtime, "
                . 'so every translation in this .po is inert'
            );

            return;
        }

        $binary = $context->read($compiled);
        $originals = $binary === null ? null : $this->moOriginals($binary);
        if ($originals === null) {
            $reporter->fail(
                "$compiled: not a readable gettext catalog — msgfmt never produced it, or it was truncated"
            );

            return;
        }

        $translated = array_keys(array_filter($entries));
        $missing = array_values(array_diff($translated, $originals));
        if ($missing === []) {
            return;
        }
        $reporter->fail(sprintf(
            '%s: %d translated string(s) from %s are missing from it (%s) — the compiled catalog is '
            . 'behind its source, so those translations never reach anyone; recompile with msgfmt',
            $compiled,
            count($missing),
            basename($catalog),
            $this->examples($missing),
        ));
    }

    /**
     * @param array<string, list<string>> $msgids
     */
    private function judgeSourceStrings(Context $context, Reporter $reporter, array $msgids): void
    {
        $literals = [];
        foreach ($this->sourceFiles($context) as $file) {
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            [$found, $dynamic] = $this->translatableStrings($file, $source);
            foreach ($found as $literal) {
                $literals[$literal] = true;
            }
            if ($dynamic > 0) {
                $reporter->warn(sprintf(
                    '%s: %d ts() call(s) with a non-literal first argument — no extractor can see those '
                    . 'strings, so they never reach l10n/ and can never be translated',
                    $file,
                    $dynamic,
                ));
            }
        }
        if ($literals === []) {
            return;
        }

        foreach ($msgids as $catalog => $known) {
            $known = array_flip($known);
            $missing = [];
            foreach (array_keys($literals) as $literal) {
                if (!isset($known[$literal]) && !isset($known[trim($literal)])) {
                    $missing[] = $literal;
                }
            }
            if ($missing === []) {
                continue;
            }
            $reporter->warn(sprintf(
                '%s: %d string(s) passed to E::ts()/{ts} appear in it as no msgid (%s) — the catalog was '
                . 'extracted before those strings existed',
                $catalog,
                count($missing),
                $this->examples($missing),
            ));
        }
    }

    /**
     * The `.po`/`.pot` files the repo ships. Empty means no `l10n/`, which is
     * the silent case.
     *
     * @return list<string>
     */
    private function catalogs(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('l10n', ['.po', '.pot'])
            : $context->findFiles('l10n', ['.po', '.pot']);
        sort($files);

        return $files;
    }

    /**
     * Files that can carry a translatable literal. Tests are not shipped UI and
     * vendored code has its own catalogs.
     *
     * @return list<string>
     */
    private function sourceFiles(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php', '.tpl'])
            : $context->findFiles('', ['.php', '.tpl']);

        return array_values(array_filter($files, static fn (string $f): bool => !str_starts_with($f, 'tests/')
            && !str_starts_with($f, 'vendor/')
            && !str_starts_with($f, 'node_modules/')));
    }

    /**
     * Literal strings this file offers for translation, and the number of calls
     * that offer something an extractor cannot read.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function translatableStrings(string $file, string $source): array
    {
        if (str_ends_with($file, '.tpl')) {
            return [$this->smartyStrings($source), 0];
        }

        $literals = [];
        $dynamic = 0;
        $pattern = '/(?<!\w)(?:E|[A-Za-z0-9_]*ExtensionUtil)::ts\s*\(\s*'
            . '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|(\S))/s';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return [[], 0];
        }
        foreach ($matches as $match) {
            if ($match[1] !== null) {
                $literals[] = str_replace(['\\\'', '\\\\'], ["'", '\\'], $match[1]);
                continue;
            }
            if ($match[2] !== null) {
                // "…$name…" is a runtime concatenation wearing a literal's
                // clothes: gettext looks up the interpolated result.
                if (str_contains($match[2], '$')) {
                    ++$dynamic;
                    continue;
                }
                $literals[] = $this->unescape($match[2]);
                continue;
            }
            ++$dynamic;
        }

        $literals = array_filter($literals, static fn (string $l): bool => $l !== '');

        return [array_values(array_unique($literals)), $dynamic];
    }

    /**
     * `{ts}…{/ts}` block bodies. A body containing further Smarty tags is not a
     * fixed string and is left alone.
     *
     * @return list<string>
     */
    private function smartyStrings(string $source): array
    {
        $found = [];
        if (preg_match_all('#\{ts(?=[\s}])[^}]*\}(.*?)\{/ts\}#s', $source, $matches) > 0) {
            foreach ($matches[1] as $body) {
                if ($body === '' || str_contains($body, '{') || str_contains($body, '}')) {
                    continue;
                }
                $found[] = $body;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * msgid => "has a non-empty translation".
     *
     * A fuzzy entry counts as present but untranslated: msgfmt leaves it out of
     * the .mo by design, so demanding it there would report every half-reviewed
     * translation as a stale catalog — while its msgid is very much in the
     * catalog and must not be reported as unextracted. Obsolete entries (`#~`)
     * are a graveyard msgfmt ignores entirely, and count as neither. The header
     * entry (empty msgid) is metadata, not a string.
     *
     * @return array<string, bool>
     */
    private function parsePo(string $source): array
    {
        $entries = [];
        $msgid = null;
        $translated = false;
        $fuzzy = false;
        $obsolete = false;
        $target = null;

        $flush = static function () use (&$entries, &$msgid, &$translated, &$fuzzy, &$obsolete, &$target): void {
            if (is_string($msgid) && $msgid !== '' && !$obsolete) {
                $entries[$msgid] = ($entries[$msgid] ?? false) || ($translated && !$fuzzy);
            }
            $msgid = null;
            $translated = false;
            $fuzzy = false;
            $obsolete = false;
            $target = null;
        };

        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, '#~')) {
                $obsolete = true;
                continue;
            }
            if (str_starts_with($line, '#')) {
                if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
                    $fuzzy = true;
                }
                continue;
            }
            if (preg_match('/^msgid\s+"(.*)"$/', $line, $match) === 1) {
                if ($msgid !== null) {
                    $flush();
                }
                $msgid = $this->unescape($match[1]);
                $target = 'msgid';
                continue;
            }
            if (preg_match('/^(msgctxt|msgid_plural)\s+"(.*)"$/', $line) === 1) {
                $target = 'other';
                continue;
            }
            if (preg_match('/^msgstr(?:\[\d+\])?\s+"(.*)"$/', $line, $match) === 1) {
                $translated = $translated || $this->unescape($match[1]) !== '';
                $target = 'msgstr';
                continue;
            }
            if (preg_match('/^"(.*)"$/', $line, $match) === 1) {
                $continuation = $this->unescape($match[1]);
                if ($target === 'msgid') {
                    $msgid = (string) $msgid . $continuation;
                } elseif ($target === 'msgstr') {
                    $translated = $translated || $continuation !== '';
                }
            }
        }
        $flush();

        return $entries;
    }

    /**
     * The untranslated strings a compiled catalog knows about, or null if the
     * file is not a gettext catalog.
     *
     * The format is four little- or big-endian longs of header, then two tables
     * of (length, offset) pairs — readable with unpack() and no dependency, and
     * the alternative (shelling out to msgunfmt) is a tool that is not installed
     * in half the places this runs.
     *
     * @return list<string>|null
     */
    private function moOriginals(string $binary): ?array
    {
        if (strlen($binary) < 24) {
            return null;
        }
        $magic = unpack('V', substr($binary, 0, 4));
        if ($magic === false) {
            return null;
        }
        $format = match ($magic[1]) {
            0x950412de => 'V',
            0xde120495 => 'N',
            default => null,
        };
        if ($format === null) {
            return null;
        }

        $header = unpack("{$format}4", substr($binary, 8, 16));
        if ($header === false) {
            return null;
        }
        $count = $header[1];
        $table = $header[2];

        $originals = [];
        for ($i = 0; $i < $count; ++$i) {
            $entry = unpack("{$format}2", substr($binary, $table + $i * 8, 8));
            if ($entry === false || strlen($binary) < $entry[2] + $entry[1]) {
                return null;
            }
            $original = substr($binary, $entry[2], $entry[1]);
            // A context is stored as "ctxt\x04msgid", a plural as
            // "singular\0plural"; both reduce to the msgid the .po names.
            if (str_contains($original, "\x04")) {
                $original = substr($original, strpos($original, "\x04") + 1);
            }
            $originals[] = explode("\0", $original)[0];
        }

        return $originals;
    }

    private function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                default => $m[1],
            },
            $value,
        );
    }

    /**
     * A few of the offending strings, shortened. Cut on a character boundary,
     * not a byte one: translatable copy is exactly the kind of string that ends
     * in a multibyte character, and half of one renders as a replacement glyph.
     *
     * @param list<string> $strings
     */
    private function examples(array $strings): string
    {
        sort($strings);
        $shown = array_slice($strings, 0, self::EXAMPLES);
        $quoted = array_map(static function (string $s): string {
            $s = str_replace("\n", ' ', $s);
            if (preg_match('/^.{0,40}/us', $s, $match) === 1) {
                $s = $match[0] === $s ? $s : $match[0] . '…';
            } elseif (strlen($s) > 40) {
                $s = substr($s, 0, 40) . '…';
            }

            return "'$s'";
        }, $shown);
        $rest = count($strings) - count($shown);

        return implode(', ', $quoted) . ($rest > 0 ? ", +$rest more" : '');
    }

    private function ships(Context $context, string $relative): bool
    {
        return $context->isGitRepo() ? $context->isTracked($relative) : $context->exists($relative);
    }
}
