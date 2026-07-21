<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A generic in the `@var` of an APIv4 action property, which fatals at runtime.
 *
 * For a class under Civi\Api4 that extends an *Action, CiviCRM's own code reads
 * the `@var` docblock of each parameter property AT RUNTIME (ReflectionUtils,
 * via Api4's parameter typing) to decide the parameter's type — and its parser
 * does not understand generics. `@var array<string, mixed>` makes the call die
 * with "Unknown parameter type" on a live site.
 *
 * The reason this needs its own rule is that NOTHING ELSE catches it. phpstan
 * is perfectly happy with `@var array<string, mixed>` — it is the more precise
 * annotation, so a well-meaning author writes it. The tests catch it only if
 * that particular action is exercised at runtime, and a new parameter often is
 * not. So the crash ships. It has been written into three separate extensions
 * this week and reverted each time.
 *
 * The house pattern is a plain `@var array` for the runtime parser PLUS a
 * separate `@phpstan-var array<...>` carrying the real shape, which the runtime
 * parser ignores and phpstan reads. This rule is the two halves of that:
 *   - FAIL: a generic ('<') in the `@var` of an action property.
 *   - WARN: an `array` `@var` with no `@phpstan-var` beside it — not a crash,
 *     but the shape is lost, and nudging it back is what keeps an author from
 *     reaching for the generic form that does crash.
 */
final class Api4ActionVarCheck implements Check
{
    public function name(): string
    {
        return 'api4-action-var';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $generics = [];
        $shapeless = [];
        $seenAction = false;

        foreach ($context->tracked('*.php') as $file) {
            if (!$this->isActionClass($file)) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            $seenAction = true;
            foreach ($this->propertyDocs($source) as [$name, $doc]) {
                if (preg_match('/@var\s+([^\s*]+)/', $doc, $match) !== 1) {
                    continue;
                }
                $type = $match[1];
                $label = basename($file) . '::$' . $name;
                if (str_contains($type, '<')) {
                    $generics[] = $label;
                } elseif (preg_match('/\barray\b/', $type) === 1 && !str_contains($doc, '@phpstan-var')) {
                    $shapeless[] = $label;
                }
            }
        }

        if (!$seenAction) {
            return;
        }

        if ($generics !== []) {
            $reporter->fail(
                'generic in the @var of an APIv4 action property: ' . implode(', ', $generics)
                . ' — core parses @var at runtime and rejects it ("Unknown parameter type");'
                . ' use a plain @var plus a separate @phpstan-var for the shape'
            );
        } else {
            $reporter->ok('no APIv4 action property carries a generic in its @var');
        }

        if ($shapeless !== []) {
            $reporter->warn(
                'array @var on an APIv4 action property with no @phpstan-var beside it: '
                . implode(', ', $shapeless) . ' — the shape is lost to phpstan'
            );
        }
    }

    /**
     * An APIv4 action lives under an Api4/Action/ directory. Test classes mirror
     * that path (tests/.../Api4/Action/FooTest.php) but are not actions, and the
     * civix shim is generated.
     */
    private function isActionClass(string $file): bool
    {
        if (!str_contains($file, '/Api4/Action/')) {
            return false;
        }
        if (str_contains($file, '.civix.php')) {
            return false;
        }

        return !str_contains($file, '/tests/') && !str_starts_with($file, 'tests/');
    }

    /**
     * Docblock/name pairs for the CLASS properties of a file.
     *
     * Walks the token stream rather than regexing the whole file so a `@var`
     * docblock inside a method body (annotating a local variable) is not mistaken
     * for a property: those live at brace depth >= 2, class properties at depth 1.
     * The visibility keyword, type and readonly/static modifiers all sit between
     * the docblock and the variable, and none of them clears the pending doc; a
     * `;`, a brace or a `function` (a method, not a property) does.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function propertyDocs(string $source): array
    {
        $props = [];
        $depth = 0;
        $doc = null;

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                } elseif ($token === ';') {
                    $doc = null;
                }
                continue;
            }

            switch ($token[0]) {
                case T_DOC_COMMENT:
                    $doc = $token[1];
                    break;
                case T_FUNCTION:
                    $doc = null;
                    break;
                case T_WHITESPACE:
                case T_PUBLIC:
                case T_PROTECTED:
                case T_PRIVATE:
                case T_VAR:
                case T_READONLY:
                case T_STATIC:
                case T_STRING:
                case T_ARRAY:
                case T_NS_SEPARATOR:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    // Modifiers and the type sit between the doc and the name;
                    // keep the pending doc across them.
                    break;
                case T_VARIABLE:
                    if ($depth === 1 && $doc !== null) {
                        $props[] = [ltrim($token[1], '$'), $doc];
                    }
                    $doc = null;
                    break;
                default:
                    // A const, a use, an attribute expression — not a property.
                    $doc = null;
            }
        }

        return $props;
    }
}
