<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A container service definition pointing at a class the extension does not
 * ship.
 *
 * This is the worst member of the dangling-class family. A missing Page class
 * breaks one URL; a missing service class breaks the container compile, and the
 * container is built before anything else can run — every page, every API call,
 * every cron job dies, and the site cannot even be reached to disable the
 * extension. Renaming a subscriber and forgetting the hook_civicrm_container
 * registration (or a services.yml `class:`) does exactly that.
 *
 * Only literal class names are judged — a string literal or Foo\Bar::class.
 * Dynamic expressions ($class, concatenation, variables) are unresolvable
 * statically and are skipped rather than guessed at, and foreign classes are
 * passed over in silence because they may legitimately live in core or a
 * dependency.
 */
final class ContainerServiceReferenceCheck implements Check
{
    public function name(): string
    {
        return 'container-service-reference';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $namespaces = ExtensionNamespace::all($context);

        foreach ($this->sources($context, ['.php']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->phpClassLiterals($source) as $class) {
                $this->judge($context, $reporter, $namespaces, $relative, $class);
            }
        }

        foreach ($this->sources($context, ['services.yml', 'services.yaml']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->yamlClassLiterals($source) as $class) {
                $this->judge($context, $reporter, $namespaces, $relative, $class);
            }
        }
    }

    /**
     * @param  list<string> $extensions
     * @return list<string>
     */
    private function sources(Context $context, array $extensions): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', $extensions)
            : $context->findFiles('', $extensions);

        return array_values(array_filter(
            $files,
            static fn (string $f): bool => !str_starts_with($f, 'tests/') && !str_starts_with($f, 'vendor/'),
        ));
    }

    /**
     * Class names handed to a container registration call. Deliberately narrow:
     * the argument must be a quoted literal or a ::class constant.
     *
     * @return list<string>
     */
    private function phpClassLiterals(string $source): array
    {
        $argument = '(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class)';
        $patterns = [
            // new Definition('Civi\Foo\Bar') — also covers ->setDefinition(id, new Definition(...)).
            '/new\s+(?:\\\\?Symfony\\\\[A-Za-z\\\\]+\\\\)?Definition\s*\(\s*' . $argument . '/',
            // ->register('id', 'Civi\Foo\Bar')
            '/->register\s*\(\s*(?:\'[^\']*\'|"[^"]*")\s*,\s*' . $argument . '/',
            // ->setClass('Civi\Foo\Bar')
            '/->setClass\s*\(\s*' . $argument . '/',
            // ->addSubscriber(new Civi\Foo\Bar()) / ->addSubscriber(Civi\Foo\Bar::class)
            '/->addSubscriber\s*\(\s*(?:new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*\(|' . $argument . ')/',
        ];

        $classes = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                foreach (array_slice($match, 1) as $candidate) {
                    if ($candidate !== '') {
                        $classes[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * `class: Civi\Foo\Bar` from a services.yml. A line parser, not a YAML
     * parser: no Symfony YAML component ships in this image, and the one key
     * that matters here is unambiguous on its own line.
     *
     * @return list<string>
     */
    private function yamlClassLiterals(string $source): array
    {
        $classes = [];
        foreach (explode("\n", $source) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^-?\s*class:\s*[\'"]?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)[\'"]?\s*$/', $line, $match) === 1) {
                $classes[] = $match[1];
            }
        }

        return array_values(array_unique($classes));
    }

    /** @param list<string> $namespaces */
    private function judge(
        Context $context,
        Reporter $reporter,
        array $namespaces,
        string $relative,
        string $class,
    ): void {
        $expected = ExtensionNamespace::ownClassFile($class, $namespaces);
        if ($expected === null) {
            return;
        }
        $ships = $context->isGitRepo() ? $context->isTracked($expected) : $context->exists($expected);
        if (!$ships) {
            $reporter->fail(
                "$relative: service class " . ltrim(str_replace('\\\\', '\\', $class), '\\')
                . " has no file $expected — the container rebuild throws and the whole site is down"
            );
        }
    }
}
