<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An Afform layout binds fields to an entity alias declared by `<af-entity>`.
 * A fieldset bound to an alias that was never declared — a rename that missed
 * one half, a block copied out of another form — does not error at install:
 * Afform renders the form, the fields have nothing to write to, and the submit
 * saves nothing. The user sees a form that "works" and loses their input.
 *
 * A duplicated alias is the same class of silent damage from the other side:
 * the second declaration wins, so half the form writes to an entity nobody
 * expects.
 *
 * The metadata half is the `.aff.json` next to the layout. Without it, Afform
 * falls back to whatever the database happens to hold (or to defaults) — that
 * is a forgotten file far more often than an intent. And a form with no
 * `permission` key inherits a default rather than a decision, which is how a
 * staff-only form ends up reachable.
 *
 * Field *names* are deliberately not validated: that needs the live schema, and
 * a static guess would either miss custom fields or cry wolf on them. Only the
 * structural contract inside the file is checked — plus an `af-field` sitting
 * outside every fieldset, which has no entity to bind to at all.
 */
final class AfformContractCheck implements Check
{
    /** Afform types that legitimately declare no entities of their own. */
    private const ENTITYLESS_TYPES = ['block', 'search'];

    public function name(): string
    {
        return 'afform-contract';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle) — an uncommitted local form is
        // not shipped. Outside git, fall back to the tree.
        $layouts = $context->isGitRepo()
            ? $context->trackedUnder('ang', ['.aff.html'])
            : $context->findFiles('ang', ['.aff.html']);
        if ($layouts === []) {
            return;
        }

        foreach ($layouts as $relative) {
            $html = $context->read($relative);
            if ($html === null) {
                continue;
            }

            $metaFile = substr($relative, 0, -strlen('.aff.html')) . '.aff.json';
            $type = null;
            $hasMeta = $context->isGitRepo()
                ? $context->isTracked($metaFile)
                : $context->exists($metaFile);

            if (!$hasMeta) {
                $reporter->warn("$relative: no $metaFile beside it — the form's metadata then comes from database defaults, which is usually a forgotten file");
            } else {
                $raw = $context->read($metaFile) ?? '';
                $meta = json_decode($raw, true);
                if (!is_array($meta)) {
                    $reporter->fail("$metaFile: invalid JSON (" . json_last_error_msg() . ') — the form cannot be installed');
                } else {
                    $type = is_string($meta['type'] ?? null) ? $meta['type'] : null;
                    if (!array_key_exists('permission', $meta)) {
                        $reporter->warn("$metaFile: no permission key — check the intended audience explicitly");
                    }
                }
            }

            $aliases = $this->declaredAliases($html, $relative, $reporter);

            // Blocks and search forms often declare no entity at all; their
            // fieldsets are bound by the including form. Only enforce the
            // binding contract once this file declares entities itself.
            $enforceBindings = $aliases !== []
                || ($type !== null && !in_array($type, self::ENTITYLESS_TYPES, true));

            if ($enforceBindings) {
                $this->checkBindings($html, $relative, $aliases, $reporter);
            }

            $this->checkStrayFields($html, $relative, $reporter);
        }
    }

    /**
     * @return list<string>
     */
    private function declaredAliases(string $html, string $relative, Reporter $reporter): array
    {
        $aliases = [];
        if (preg_match_all('/<af-entity\b[^>]*>/i', $html, $tags) < 1) {
            return [];
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $tag, $m) !== 1) {
                $reporter->fail("$relative: an <af-entity> has no name attribute — nothing can bind to it");
                continue;
            }
            $alias = $m[1];
            if (in_array($alias, $aliases, true)) {
                $reporter->fail("$relative: entity alias '$alias' is declared twice — the second declaration wins and half the form writes to the wrong entity");
                continue;
            }
            $aliases[] = $alias;
        }

        return $aliases;
    }

    /**
     * @param list<string> $aliases
     */
    private function checkBindings(string $html, string $relative, array $aliases, Reporter $reporter): void
    {
        foreach (['af-fieldset', 'data-entity'] as $attribute) {
            $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*["\']([^"\']*)["\']/i';
            if (preg_match_all($pattern, $html, $matches) < 1) {
                continue;
            }
            foreach (array_unique($matches[1]) as $alias) {
                if ($alias === '' || in_array($alias, $aliases, true)) {
                    continue;
                }
                $reporter->fail("$relative: $attribute=\"$alias\" binds to an entity alias that is not declared by any <af-entity> (declared: " . (implode(', ', $aliases) ?: 'none') . ') — the fields render but save nothing');
            }
        }
    }

    /**
     * An af-field that no af-fieldset element encloses. Approximated by
     * position: a field before the first fieldset opening tag, or after the
     * point where fieldset nesting has closed again, has no entity to bind to.
     */
    private function checkStrayFields(string $html, string $relative, Reporter $reporter): void
    {
        if (preg_match_all('/<af-field\b[^>]*>/i', $html, $fields, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        $ranges = $this->fieldsetRanges($html);
        foreach ($fields[0] as [$tag, $offset]) {
            foreach ($ranges as [$start, $end]) {
                if ($offset >= $start && $offset < $end) {
                    continue 2;
                }
            }
            $name = preg_match('/\bname\s*=\s*["\']([^"\']*)["\']/i', $tag, $m) === 1 ? $m[1] : '?';
            $reporter->warn("$relative: <af-field name=\"$name\"> sits outside every af-fieldset — it has no entity to bind to");
        }
    }

    /**
     * Byte ranges covered by an element carrying af-fieldset, by walking tags
     * and tracking depth. Self-closing and void forms are ignored, since an
     * empty fieldset cannot contain a field anyway.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function fieldsetRanges(string $html): array
    {
        if (preg_match_all('#<(/?)([a-z0-9-]+)\b([^>]*)>#i', $html, $tags, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $ranges = [];
        /** @var list<array{name: string, start: int, depth: int}> $open */
        $open = [];
        $depth = [];

        foreach ($tags[0] as $index => [$tag, $offset]) {
            $closing = $tags[1][$index][0] !== '';
            $name = strtolower($tags[2][$index][0]);
            $attributes = $tags[3][$index][0];
            if (str_ends_with(rtrim($attributes), '/')) {
                continue;
            }

            if (!$closing) {
                $depth[$name] = ($depth[$name] ?? 0) + 1;
                if (preg_match('/\baf-fieldset\s*=/i', $attributes) === 1) {
                    $open[] = ['name' => $name, 'start' => $offset + strlen($tag), 'depth' => $depth[$name]];
                }
                continue;
            }

            $current = $depth[$name] ?? 0;
            $last = $open === [] ? null : $open[count($open) - 1];
            if ($last !== null && $last['name'] === $name && $last['depth'] === $current) {
                array_pop($open);
                $ranges[] = [$last['start'], $offset];
            }
            $depth[$name] = max(0, $current - 1);
        }

        // Unclosed fieldsets: treat as running to the end of the file rather
        // than reporting every field inside them as stray.
        foreach ($open as $unclosed) {
            $ranges[] = [$unclosed['start'], strlen($html)];
        }

        return $ranges;
    }
}
