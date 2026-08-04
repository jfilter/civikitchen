<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The routes an extension declares in xml/Menu — generated per repo.
 *
 * Regenerate with:
 *   php tools/gen-route-catalog.php <extension-dir>
 *
 * phpstan analyses PHP, and a menu XML is what turns a static method into a
 * URL a browser can GET. Rather than teach the rule to read XML at analysis
 * time, the mapping is generated into .ck-routes.json and read from there;
 * a missing file leaves the XML-declared half of the rule inert, which is
 * the right default for a repo that has no routes.
 */
final class RouteCatalog
{
    /** @var array<string, array<string, string>> class => method => route path */
    private array $handlers = [];

    /** @var array<string, string> class => route path, for page classes */
    private array $pages = [];

    public function __construct(?string $file)
    {
        if ($file === null || !is_file($file)) {
            return;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
            return;
        }
        foreach ($data['routes'] as $route) {
            if (!is_array($route) || !isset($route['class']) || !is_string($route['class'])) {
                continue;
            }
            $class = strtolower(ltrim($route['class'], '\\'));
            $path = is_string($route['path'] ?? null) ? $route['path'] : '';
            if (isset($route['method']) && is_string($route['method'])) {
                $this->handlers[$class][strtolower($route['method'])] = $path;
            } else {
                $this->pages[$class] = $path;
            }
        }
    }

    /** The route a static method answers, or null if it answers none. */
    public function routeForMethod(string $class, string $method): ?string
    {
        return $this->handlers[strtolower($class)][strtolower($method)] ?? null;
    }

    /** The route a page class answers through run(). */
    public function routeForPage(string $class): ?string
    {
        return $this->pages[strtolower($class)] ?? null;
    }
}
