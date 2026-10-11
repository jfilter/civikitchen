<?php

declare(strict_types=1);

namespace CiviKitchen\Mago;

/**
 * Whether a file asks PHP about a symbol before using it. The check is per file:
 * one guard covers every use of that symbol there, whichever branch it sits in.
 * Guards inside comments do not count.
 */
final class Guards
{
    private const CALLS = ['function_exists', 'is_callable', 'class_exists', 'interface_exists', 'enum_exists', 'trait_exists', 'method_exists', 'defined'];

    /** @var array<string, array{calls: list<array{0: string, 1: list<?string>}>, version: ?string}> */
    private static array $files = [];

    public static function function(string $contents, string $function): bool
    {
        foreach (self::parse($contents)['calls'] as [$call, $arguments]) {
            if (in_array($call, ['function_exists', 'is_callable'], true) && self::same($arguments[0] ?? null, $function)) {
                return true;
            }
        }

        return false;
    }

    /** `class_exists('A\B')` or `class_exists(B::class)`, and the interface, enum and trait forms. */
    public static function classLike(string $contents, string $class): bool
    {
        foreach (self::parse($contents)['calls'] as [$call, $arguments]) {
            if (str_ends_with($call, '_exists') && $call !== 'function_exists' && $call !== 'method_exists' && self::same($arguments[0] ?? null, $class)) {
                return true;
            }
        }

        return false;
    }

    /** `defined('NAME')`. */
    public static function constant(string $contents, string $constant): bool
    {
        foreach (self::parse($contents)['calls'] as [$call, $arguments]) {
            if ($call === 'defined' && self::same($arguments[0] ?? null, $constant)) {
                return true;
            }
        }

        return false;
    }

    /** `method_exists(X, 'm')`: X must name the class when it is a literal, any expression vouches by name. */
    public static function method(string $contents, string $class, string $method): bool
    {
        foreach (self::parse($contents)['calls'] as [$call, $arguments]) {
            if ($call === 'method_exists' && self::same($arguments[1] ?? null, $method)
                && (($arguments[0] ?? null) === null || self::same($arguments[0], $class))) {
                return true;
            }
        }

        return false;
    }

    /** The newest MAJOR.MINOR a `PHP_VERSION_ID` or `version_compare(PHP_VERSION, …)` check names, if any. */
    public static function version(string $contents): ?string
    {
        return self::parse($contents)['version'];
    }

    private static function same(?string $guarded, string $name): bool
    {
        return $guarded !== null && strcasecmp(ltrim($guarded, '\\'), ltrim($name, '\\')) === 0;
    }

    /** @return array{calls: list<array{0: string, 1: list<?string>}>, version: ?string} */
    private static function parse(string $contents): array
    {
        $key = md5($contents);
        if (isset(self::$files[$key])) {
            return self::$files[$key];
        }
        $tokens = array_values(array_filter(
            token_get_all($contents),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $namespace = '';
        $imports = [];
        $calls = [];
        $versions = [];
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_NAMESPACE && is_array($tokens[$index + 1] ?? null)) {
                $namespace = trim($tokens[$index + 1][1], '\\');
            } elseif (is_array($token) && $token[0] === T_USE && !in_array(self::text($tokens[$index + 1] ?? ''), ['(', 'function', 'const'], true)) {
                $imports = [...$imports, ...self::imports($tokens, $index + 1)];
            } elseif (strcasecmp(ltrim($text, '\\'), 'PHP_VERSION_ID') === 0) {
                $number = self::text($tokens[$index + 2] ?? '');
                $number = ctype_digit($number) ? $number : self::text($tokens[$index - 2] ?? '');
                if (ctype_digit($number) && strlen($number) === 5) {
                    $versions[] = intdiv((int) $number, 10000) . '.' . intdiv((int) $number % 10000, 100);
                }
            } elseif (in_array(strtolower(ltrim($text, '\\')), [...self::CALLS, 'version_compare'], true) && self::text($tokens[$index + 1] ?? '') === '(') {
                $calls[] = [strtolower(ltrim($text, '\\')), self::arguments($tokens, $index + 2)];
            }
        }
        $resolve = static function (?array $argument) use ($namespace, $imports): ?string {
            [$kind, $value] = $argument ?? ['', ''];
            if ($kind !== 'class') {
                return $kind === 'string' ? $value : null;
            }
            if (str_starts_with($value, '\\')) {
                return ltrim($value, '\\');
            }
            $first = strtolower(explode('\\', $value)[0]);
            if (isset($imports[$first])) {
                return $imports[$first] . substr($value, strlen($first));
            }

            return ltrim($namespace . '\\' . $value, '\\');
        };
        $resolved = [];
        foreach ($calls as [$call, $arguments]) {
            if ($call !== 'version_compare') {
                $resolved[] = [$call, array_map($resolve, $arguments)];
                continue;
            }
            // version_compare(PHP_VERSION, '8.3', …), either order.
            $values = array_map(static fn(?array $argument): string => $argument[1] ?? '', array_slice($arguments, 0, 2));
            $constant = array_search('PHP_VERSION', array_map(static fn(string $value): string => ltrim($value, '\\'), $values), true);
            if ($constant !== false && preg_match('/^(\d+)\.(\d+)/', $values[1 - $constant] ?? '', $match) === 1) {
                $versions[] = "{$match[1]}.{$match[2]}";
            }
        }
        usort($versions, 'version_compare');

        return self::$files[$key] = ['calls' => $resolved, 'version' => array_pop($versions)];
    }

    /**
     * Each argument as a literal string, a `Name::class`, a bare constant, or null for any other expression.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: 'string'|'class'|'constant', 1: string}|null>
     */
    private static function arguments(array $tokens, int $index): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        for (; isset($tokens[$index]); $index++) {
            $text = self::text($tokens[$index]);
            if ($depth === 0 && ($text === ',' || $text === ')')) {
                $arguments[] = self::argument($current);
                $current = [];
                if ($text === ')') {
                    break;
                }
                continue;
            }
            $depth += match ($text) { '(', '[', '{' => 1, ')', ']', '}' => -1, default => 0 };
            $current[] = $tokens[$index];
        }

        return $arguments;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: 'string'|'class'|'constant', 1: string}|null
     */
    private static function argument(array $tokens): ?array
    {
        if (count($tokens) === 1 && is_array($tokens[0]) && in_array($tokens[0][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return ['constant', $tokens[0][1]];
        }
        if (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return ['string', str_replace('\\\\', '\\', substr($tokens[0][1], 1, -1))];
        }
        if (count($tokens) === 3 && is_array($tokens[0]) && self::text($tokens[1]) === '::' && strcasecmp(self::text($tokens[2]), 'class') === 0
            && in_array($tokens[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return ['class', $tokens[0][1]];
        }

        return null;
    }

    /**
     * `use A\B, C\D as E;` as lowercase alias => full name.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens, int $index): array
    {
        $imports = [];
        while (isset($tokens[$index]) && is_array($tokens[$index]) && in_array($tokens[$index][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = ltrim($tokens[$index][1], '\\');
            $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
            $index++;
            if (is_array($tokens[$index] ?? null) && $tokens[$index][0] === T_AS && is_array($tokens[$index + 1] ?? null)) {
                $alias = $tokens[$index + 1][1];
                $index += 2;
            }
            $imports[strtolower($alias)] = $name;
            if (self::text($tokens[$index] ?? '') !== ',') {
                break;
            }
            $index++;
        }

        return $imports;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private static function text(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }
}
