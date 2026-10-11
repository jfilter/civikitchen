<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer\Disallowed;

use LogicException;

/**
 * The CiviCRM call bans from phpstan-config/civicrm-disallowed.php, the list
 * phpstan's disallowed-calls extension reads too.
 */
final class Bans
{
    private const FILE = __DIR__ . '/../../../../phpstan-config/civicrm-disallowed.php';

    private function __construct()
    {
    }

    /** @return array<string, array{name: string, message: string, allowIn: list<string>}> lower-case function name => ban */
    public static function functions(): array
    {
        $bans = [];
        foreach (self::entries('disallowedFunctionCalls', 'function') as $ban) {
            $bans[strtolower($ban['name'])] = $ban;
        }

        return $bans;
    }

    /**
     * Keyed by lower-case method name: mago resolves the call to the banned
     * declaration, so the method name alone tells the bans apart.
     *
     * @return array<string, array{name: string, class: string, method: string, message: string, allowIn: list<string>}>
     */
    public static function staticMethodsByMethod(): array
    {
        $bans = [];
        foreach (self::entries('disallowedStaticCalls', 'method') as $ban) {
            [$class, $method] = explode('::', $ban['name']);
            $key = strtolower($method);
            if (isset($bans[$key])) {
                throw new LogicException("two static-call bans on a method named {$method}: tell them apart by receiver class first");
            }
            $bans[$key] = $ban + ['class' => $class, 'method' => $method];
        }

        return $bans;
    }

    /** Whether a workspace-relative path is exempt, matched like the phpstan extension's allowIn. */
    public static function allowed(array $ban, string $path): bool
    {
        foreach ($ban['allowIn'] as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{name: string, message: string, allowIn: list<string>}> */
    private static function entries(string $section, string $key): array
    {
        $config = require self::FILE;
        $bans = [];
        foreach ($config['parameters'][$section] as $entry) {
            $name = substr($entry[$key], 0, -2);
            $bans[] = ['name' => $name, 'message' => $entry['message'], 'allowIn' => $entry['allowIn'] ?? []];
        }

        return $bans;
    }
}
