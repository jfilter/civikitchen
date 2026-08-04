<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The routes an extension declares in xml/Menu.
 *
 * phpstan analyses PHP, and a menu XML is what turns a static method into a
 * URL a browser can GET — so the rule has to read the XML from somewhere.
 * It reads it from the repo directly. A generated .ck-routes.json
 * (tools/gen-route-catalog.php) is honoured when present, as an override
 * for a repo whose routes are not in the usual place, but its absence must
 * never make the rule inert: a route added to the XML without regenerating
 * a catalog would leave the gate configured and blind.
 */
final class RouteCatalog
{
    /** @var array<string, array<string, string>> class => method => route path */
    private array $handlers = [];

    /** @var array<string, string> class => route path, for page classes */
    private array $pages = [];

    public function __construct(?string $file, ?string $extensionDir = null)
    {
        $routes = $file !== null && is_file($file)
            ? self::fromJson($file)
            : self::fromMenuXml((string) $extensionDir);

        foreach ($routes as $route) {
            $class = strtolower(ltrim($route['class'], '\\'));
            if ($class === '') {
                continue;
            }
            if ($route['method'] === null) {
                $this->pages[$class] = $route['path'];
            } else {
                $this->handlers[$class][strtolower($route['method'])] = $route['path'];
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

    /**
     * The page_callback targets of every xml/Menu/*.xml in an extension.
     *
     * A callback is either `Some\Class::method` (a PSR-7 handler or another
     * static callback) or a bare class name (a CRM_Core_Page/Form, entered
     * through run()/buildQuickForm()). Shared with the generator, so both
     * paths see exactly the same routes.
     *
     * @return list<array{path: string, class: string, method: ?string}>
     */
    public static function fromMenuXml(string $extensionDir): array
    {
        if ($extensionDir === '') {
            return [];
        }
        $routes = [];
        foreach (glob($extensionDir . '/xml/Menu/*.xml') ?: [] as $file) {
            $xml = @simplexml_load_string((string) file_get_contents($file));
            if ($xml === false) {
                continue;
            }
            foreach ($xml->item as $item) {
                $callback = trim((string) $item->page_callback);
                if ($callback === '') {
                    continue;
                }
                [$class, $method] = array_pad(explode('::', $callback, 2), 2, null);
                $routes[] = [
                    'path' => trim((string) $item->path),
                    'class' => ltrim((string) $class, '\\'),
                    'method' => $method === null ? null : trim($method),
                ];
            }
        }
        usort($routes, static fn (array $a, array $b): int => [$a['class'], (string) $a['method']] <=> [$b['class'], (string) $b['method']]);

        return $routes;
    }

    /**
     * @return list<array{path: string, class: string, method: ?string}>
     */
    private static function fromJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['routes'] ?? null)) {
            return [];
        }
        $routes = [];
        foreach ($data['routes'] as $route) {
            if (!is_array($route) || !is_string($route['class'] ?? null)) {
                continue;
            }
            $routes[] = [
                'path' => is_string($route['path'] ?? null) ? $route['path'] : '',
                'class' => $route['class'],
                'method' => is_string($route['method'] ?? null) ? $route['method'] : null,
            ];
        }

        return $routes;
    }
}
