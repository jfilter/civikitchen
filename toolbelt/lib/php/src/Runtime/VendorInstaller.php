<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use CiviKitchen\Toolbelt\Process\Runner;
use JsonException;

/**
 * `composer install` for extension checkouts that ship a composer.json but no
 * vendor/. Runs as the checkout's owner, so vendor/ belongs to that user.
 */
final class VendorInstaller
{
    public function __construct(private readonly Runner $runner = new Runner())
    {
    }

    /** @param list<string> $directories */
    public function install(array $directories): int
    {
        $status = 0;
        foreach ($directories as $directory) {
            $directory = rtrim($directory, '/');
            [$action, $reason] = $this->plan($directory);
            if ($action === 'note') {
                fwrite(STDERR, "ck: skipping composer install in {$directory}: {$reason}\n");
            } elseif ($action === 'error') {
                fwrite(STDERR, "ck: {$directory}: {$reason}\n");
                $status = 1;
            } elseif ($action === 'install') {
                echo "ck: composer install in {$directory}\n";
                if ($this->runner->passthrough(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], null, $directory) !== 0) {
                    fwrite(STDERR, "ck: composer install failed in {$directory}\n");
                    $status = 1;
                }
            }
        }
        return $status;
    }

    /** @return array{string, string} install, skip, note or error, and why */
    private function plan(string $directory): array
    {
        // composer writes autoload.php last, so an interrupted install is redone.
        if (!is_file($directory . '/composer.json') || is_file($directory . '/vendor/autoload.php')) {
            return ['skip', ''];
        }
        try {
            $manifest = self::decode($directory . '/composer.json');
            $lock = is_file($directory . '/composer.lock') ? self::decode($directory . '/composer.lock') : [];
        } catch (JsonException $e) {
            return ['error', 'unreadable composer file: ' . $e->getMessage()];
        }
        $names = [
            ...array_column([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])], 'name'),
            ...array_keys([...(array) ($manifest['require'] ?? []), ...(array) ($manifest['require-dev'] ?? [])]),
        ];
        // A vendored core would load a second CiviCRM inside the running one.
        if (in_array('civicrm/civicrm-core', $names, true)) {
            return ['note', 'it requires civicrm/civicrm-core; build a runtime vendor/ with composer update --no-dev'];
        }
        if (is_writable($directory)) {
            return ['install', ''];
        }
        // Platform requirements (php, ext-*) need no vendor/.
        $packages = array_filter(array_keys((array) ($manifest['require'] ?? [])), static fn($name): bool => str_contains((string) $name, '/'));
        return $packages === []
            ? ['skip', '']
            : ['note', 'not writable here (a read-only mount) and no vendor/ for ' . implode(', ', $packages)];
    }

    /** @return array<mixed> */
    private static function decode(string $file): array
    {
        return (array) json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
