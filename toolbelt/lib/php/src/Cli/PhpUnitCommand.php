<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class PhpUnitCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        if (!is_file('info.xml')) {
            fwrite(STDERR, "ckphpunit: no info.xml here - run from the extension root.\n");
            return 2;
        }
        $config = $this->configuration($arguments);
        $canary = is_file('/usr/local/share/civikitchen/tx-canary.php')
            ? '/usr/local/share/civikitchen/tx-canary.php' : $this->checkoutRoot . '/toolbelt/lib/tx-canary.php';
        if (getenv('CK_TX_CANARY') === '0' || $config === '' || !is_file($canary)) {
            if (!is_file($canary)) {
                fwrite(STDERR, "ckphpunit: no canary in this image - running plain phpunit.\n");
            }
            return $this->runner->passthrough(['phpunit', ...$arguments]);
        }
        $generated = tempnam(sys_get_temp_dir(), 'ckphpunit-canary-');
        if ($generated === false) {
            fwrite(STDERR, "ckphpunit: cannot create generated config\n");
            return 2;
        }
        register_shutdown_function(static fn() => @unlink($generated));
        if (!$this->generateConfig($config, $generated, $canary)) {
            return 2;
        }
        $clean = [];
        $skip = false;
        foreach ($arguments as $argument) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if (in_array($argument, ['-c', '--configuration'], true)) {
                $skip = true;
                continue;
            }
            if (!str_starts_with($argument, '--configuration=')) {
                $clean[] = $argument;
            }
        }
        return $this->runner->passthrough(['phpunit', '-c', $generated, ...$clean]);
    }

    /** @param list<string> $arguments */
    private function configuration(array $arguments): string
    {
        $config = '';
        for ($index = 0; $index < count($arguments); $index++) {
            if (in_array($arguments[$index], ['-c', '--configuration'], true)) {
                $config = $arguments[$index + 1] ?? '';
            } elseif (str_starts_with($arguments[$index], '--configuration=')) {
                $config = substr($arguments[$index], 16);
            }
        }
        if ($config === '') {
            foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        return $config;
    }

    private function generateConfig(string $source, string $destination, string $canary): bool
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        if (!@$document->load($source) || !$document->documentElement instanceof DOMElement) {
            fwrite(STDERR, "ckphpunit: cannot parse {$source}\n");
            return false;
        }
        $root = $document->documentElement;
        $real = realpath($source);
        $base = dirname($real === false ? $source : $real);
        $absolute = static fn(string $path): string => $path === '' || str_starts_with($path, '/') || str_starts_with($path, 'phar://')
            ? $path : $base . '/' . $path;
        foreach (['bootstrap', 'cacheResultFile', 'extensionsDirectory', 'cacheDirectory'] as $attribute) {
            if ($root->hasAttribute($attribute)) {
                $root->setAttribute($attribute, $absolute($root->getAttribute($attribute)));
            }
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//testsuite/directory|//testsuite/file|//testsuite/exclude'
            . '|//coverage//directory|//coverage//file|//source//directory|//source//file'
            . '|//whitelist//directory|//whitelist//file');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $node->nodeValue = $absolute(trim($node->nodeValue ?? ''));
            }
        }
        $listeners = $root->getElementsByTagName('listeners')->item(0);
        if (!$listeners instanceof DOMElement) {
            $listeners = $document->createElement('listeners');
            $root->appendChild($listeners);
        }
        $listener = $document->createElement('listener');
        $listener->setAttribute('class', 'Civi\\CkTest\\TransactionCanaryListener');
        $listener->setAttribute('file', $canary);
        $listeners->appendChild($listener);
        return $document->save($destination) !== false;
    }
}
