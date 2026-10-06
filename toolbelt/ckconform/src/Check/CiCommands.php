<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/**
 * What the CI jobs judging this extension actually run: their workflow text
 * without comments, the gates behind `ck` subcommands and the shared CI, and
 * the npm and composer scripts they invoke by name, followed transitively.
 */
final class CiCommands
{
    /** The tools each `ck ci` gate runs (toolbelt/lib/php/src/Cli/CiCommand.php). */
    private const CI_GATES = [
        'cklint' => 'cklint phpcs',
        'ckconform' => 'ckconform',
        'ckcivix' => 'ckcivix',
        'ckfmt' => 'ckfmt',
        'ckcoverage' => 'ckcoverage phpunit',
        'phpunit-extra' => 'phpunit',
        'phpstan' => 'phpstan',
        'phpstan-tests' => 'phpstan',
        'ckcompat' => 'ckcompat',
        'ckdeps' => 'ckdeps',
        'cktaint' => 'cktaint',
        'cksmarty' => 'cksmarty',
        'ckeslint' => 'ckeslint',
    ];

    /** Tools a `ck <subcommand>` runs beyond its own `ck<subcommand>` binary. */
    private const CK_EXTRAS = ['lint' => 'phpcs', 'coverage' => 'phpunit', 'test' => 'ckphpunit phpunit'];

    /** Package-manager flags that take the next word as their value. */
    private const VALUE_FLAGS = ['--prefix', '-C', '--cwd', '--dir', '-w', '--workspace', '--filter', '-d', '--working-dir'];

    /** Comment-free workflow text plus everything it runs indirectly; '' without a scoped workflow. */
    public static function reachable(Context $context): string
    {
        $text = '';
        foreach ($context->scopedWorkflows() as $body) {
            $text .= self::withoutComments($body) . "\n";
        }
        if ($text === '') {
            return '';
        }
        if ($context->scopedJobsCalling(Context::SHARED_CI) !== []) {
            $text .= implode(' ', self::CI_GATES) . "\n";
        }

        return self::expandCk(self::withScripts($context, $text));
    }

    /** Whether $token appears as a command word, not inside a longer name, a dotfile or a directory path. */
    public static function runs(string $text, string $token): bool
    {
        return preg_match('/(?<![\w.-])' . preg_quote($token, '/') . '(?![\w\/-]|\.(?!phar\b))/', $text) === 1;
    }

    /**
     * Workflow text with YAML comments removed, so a step a comment describes
     * never counts as one that runs. Line-based: a '#' inside a quoted value
     * is dropped too, which errs toward reporting a missing runner.
     */
    public static function withoutComments(string $yaml): string
    {
        $out = [];
        foreach (explode("\n", $yaml) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $out[] = preg_replace('/\s+#.*$/', '', $line) ?? $line;
        }

        return implode("\n", $out);
    }

    /** `ck ci` becomes the gates it runs (its --only/--skip lists dropped), `ck <x>` gains `ck<x>`. */
    private static function expandCk(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\w.-])ck[ \t]+([a-z][\w-]*)([^\n;&|]*)/',
            static fn (array $call): string => $call[1] === 'ci'
                ? implode(' ', self::selectedGates($call[2]))
                : 'ck' . $call[1] . ' ' . (self::CK_EXTRAS[$call[1]] ?? '') . $call[2],
            $text,
        ) ?? $text;
    }

    /** @return array<string, string> the gates `ck ci <arguments>` runs after --only/--skip */
    private static function selectedGates(string $arguments): array
    {
        $gates = self::CI_GATES;
        preg_match_all('/--(only|skip)(?:=|[ \t]+)["\']?([\w,-]+)/', $arguments, $options, PREG_SET_ORDER);
        foreach ($options as [, $option, $list]) {
            $names = array_flip(explode(',', $list));
            $gates = $option === 'only' ? array_intersect_key($gates, $names) : array_diff_key($gates, $names);
        }

        return $gates;
    }

    private static function withScripts(Context $context, string $text): string
    {
        $tables = [
            'npm' => self::scripts($context, 'package.json'),
            'composer' => self::scripts($context, 'composer.json'),
        ];
        $seen = [];
        for ($pending = $text; $pending !== '';) {
            $found = '';
            foreach (self::invocations($pending) as [$manager, $name]) {
                if (isset($seen[$manager][$name]) || !isset($tables[$manager][$name])) {
                    continue;
                }
                $seen[$manager][$name] = true;
                $found .= implode("\n", $tables[$manager][$name]) . "\n";
            }
            $text .= $found;
            $pending = $found;
        }

        return $text;
    }

    /** @return list<array{0: 'npm'|'composer', 1: string}> script invocations: `npm test`, `yarn run x`, `composer x`, `@x` */
    private static function invocations(string $text): array
    {
        $found = [];
        preg_match_all('/(?<![\w.\/-])(npm|yarn|pnpm|bun|composer)(?:\.phar)?((?:[ \t]+[^\s;&|]+)*)/', $text, $calls, PREG_SET_ORDER);
        foreach ($calls as [, $manager, $arguments]) {
            $words = preg_split('/[ \t]+/', trim($arguments)) ?: [];
            for ($i = 0, $count = count($words); $i < $count; $i++) {
                $word = trim($words[$i], '"\'');
                if (in_array($word, self::VALUE_FLAGS, true)) {
                    $i++;
                } elseif ($word !== '' && !str_starts_with($word, '-') && $word !== 'run' && $word !== 'run-script') {
                    $found[] = [$manager === 'composer' ? 'composer' : 'npm', $word];
                    break;
                }
            }
        }
        preg_match_all('/(?<![\w@])@([A-Za-z0-9:_-]+)/', $text, $references);
        foreach ($references[1] as $name) {
            $found[] = ['composer', $name];
        }

        return $found;
    }

    /** @return array<string, list<string>> script name => command lines, over every tracked manifest of that name */
    private static function scripts(Context $context, string $manifest): array
    {
        $table = [];
        foreach ($context->tracked($manifest, Context::outsideNodeModules(...)) as $file) {
            if (str_starts_with($file, 'vendor/') || str_contains($file, '/vendor/')) {
                continue;
            }
            $scripts = $context->json($file)['scripts'] ?? null;
            foreach (is_array($scripts) ? $scripts : [] as $name => $body) {
                foreach (is_array($body) ? $body : [$body] as $line) {
                    if (is_string($line)) {
                        $table[(string) $name][] = $line;
                    }
                }
            }
        }

        return $table;
    }
}
