<?php

declare(strict_types=1);

namespace CiviKitchen\Mago;

use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\PropertyInitializationProvider;
use Mago\Sdk\Extension;
use Mago\Sdk\Linter\Rule;
use InvalidArgumentException;
use LogicException;

/**
 * Every class in src/Linter named *Rule is a linter rule, every class in
 * src/Analyzer named *Hook is registered by the hook interfaces it implements.
 */
final class CiviKitchenExtension
{
    private const REGISTRARS = [
        NodeAnalysisHook::class => 'registerNodeAnalysisHook',
        MethodCallAnalysisHook::class => 'registerMethodCallAnalysisHook',
        ClassLikeAnalysisHook::class => 'registerClassLikeAnalysisHook',
        IssueFilterHook::class => 'registerIssueFilterHook',
        PropertyInitializationProvider::class => 'registerPropertyInitializationProvider',
    ];

    private function __construct()
    {
    }

    public static function create(Options $options): Extension
    {
        $rules = self::rules($options);
        $hooks = array_map(static fn (string $class): object => new $class($options), self::classes('Analyzer', 'Hook'));

        return new Extension(
            identifier: 'civikitchen/checks',
            name: 'CiviKitchen checks',
            version: '1.0.0',
            linterRules: $rules,
            analyzerPlugins: [new class ($hooks) implements Plugin {
                /** @param list<object> $hooks */
                public function __construct(private readonly array $hooks)
                {
                }

                public function getDefinition(): PluginDefinition
                {
                    return new PluginDefinition('civikitchen', 'CiviKitchen', 'CiviCRM contract checks.');
                }

                public function register(PluginRegistry $registry): void
                {
                    foreach ($this->hooks as $hook) {
                        $registered = false;
                        foreach (CiviKitchenExtension::registrars() as $interface => $method) {
                            if ($hook instanceof $interface) {
                                $registry->{$method}($hook);
                                $registered = true;
                            }
                        }
                        if (!$registered) {
                            throw new LogicException($hook::class . ' implements no known hook interface');
                        }
                    }
                }
            }],
        );
    }

    /** @return array<class-string, string> */
    public static function registrars(): array
    {
        return self::REGISTRARS;
    }

    /**
     * The linter rules minus --disable=<code>,…, each scoped by its --exclude=<code>:<glob>,… entries.
     *
     * @return list<Rule>
     */
    private static function rules(Options $options): array
    {
        $rules = [];
        foreach (self::classes('Linter', 'Rule') as $class) {
            $rule = new $class($options);
            $rules[$rule->getDefinition()->code] = $rule;
        }
        $excludes = [];
        foreach ($options->list('exclude') ?? [] as $entry) {
            [$code, $glob] = explode(':', $entry, 2) + [1 => ''];
            $excludes[self::known($code, $rules)][] = $glob !== '' ? $glob : throw new InvalidArgumentException("civikitchen mago extension: --exclude entry {$entry} needs <code>:<glob>");
        }
        foreach ($options->list('disable') ?? [] as $code) {
            unset($rules[self::known($code, $rules)]);
        }
        foreach ($excludes as $code => $globs) {
            if (isset($rules[$code])) {
                $rules[$code] = new ScopedRule($rules[$code], $globs);
            }
        }

        return array_values($rules);
    }

    /** @param array<string, Rule> $rules */
    private static function known(string $code, array $rules): string
    {
        return isset($rules[$code]) ? $code : throw new InvalidArgumentException("civikitchen mago extension: no linter rule {$code}");
    }

    /** @return list<class-string> */
    private static function classes(string $directory, string $suffix): array
    {
        $classes = [];
        foreach (glob(__DIR__ . "/{$directory}/*{$suffix}.php") ?: [] as $file) {
            $classes[] = __NAMESPACE__ . "\\{$directory}\\" . basename($file, '.php');
        }

        return $classes;
    }
}
