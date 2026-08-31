<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Runtime\ExtensionArchiveInstaller;
use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;
use Throwable;

final class InternalRuntimeCommand implements Command
{
    public function __construct(private readonly string $checkoutRoot)
    {
    }

    public function run(array $arguments): int
    {
        $operation = array_shift($arguments) ?? '';
        $autoload = $this->checkoutRoot . '/packages/civikitchen-scenario-schema/vendor/autoload.php';
        if (!is_file($autoload)) {
            $autoload = '/usr/local/share/civikitchen/scenario-schema/vendor/autoload.php';
        }
        $inspector = new ExtensionInspector($autoload);
        try {
            if ($operation === 'install-extension-archive' && count($arguments) === 4) {
                (new ExtensionArchiveInstaller($inspector))->install($arguments[0], $arguments[1], $arguments[2], $arguments[3]);
                return 0;
            }
            if ($operation === 'assert-extension-version' && count($arguments) === 3) {
                $xml = $inspector->load($arguments[0], $arguments[1]);
                $inspector->assertVersion($xml, $arguments[2]);
                return 0;
            }
            if ($operation === 'extension-key' && count($arguments) === 1) {
                echo $inspector->key($arguments[0]);
                return 0;
            }
            if ($operation === 'extension-requires' && count($arguments) === 1) {
                foreach ($inspector->requirements($arguments[0]) as $key) {
                    echo $key, "\n";
                }
                return 0;
            }
            if ($operation === 'extension-list-contains' && count($arguments) === 1) {
                $list = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                foreach (is_array($list) ? $list : [] as $extension) {
                    if (is_array($extension) && ($extension['key'] ?? null) === $arguments[0]) {
                        return 0;
                    }
                }
                return 1;
            }
            fwrite(STDERR, "ck: invalid internal runtime command\n");
            return 2;
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }
}
