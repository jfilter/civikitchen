<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Scaffold;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class ExtensionEditor
{
    public function rewriteLicense(string $directory, string $license, string $holder): void
    {
        if ($license === 'Proprietary') {
            $this->rewriteInfoLicense($directory . '/info.xml');
            $text = "Copyright (C) " . date('Y') . " {$holder}. All rights reserved.\n\n"
                . "This software is proprietary. It is not licensed for redistribution or use\n"
                . "outside the terms agreed with the copyright holder.\n";
            $this->write($directory . '/LICENSE.txt', $text);
            $readme = $this->read($directory . '/README.md');
            $next = preg_replace(
                '/, licensed under \[[^]]+\]\(LICENSE\.txt\)\./',
                ', distributed as proprietary software under the terms in [LICENSE.txt](LICENSE.txt).',
                $readme,
                1,
                $count,
            );
            if ($next === null || $count !== 1) {
                throw new RuntimeException('could not rewrite the README license line');
            }
            $this->write($directory . '/README.md', $next);
            return;
        }
        $file = $directory . '/LICENSE.txt';
        $text = $this->read($file);
        $line = 'Copyright (C) ' . date('Y') . " {$holder}";
        $next = preg_replace('/^Copyright[^\r\n]*$/mi', $line, $text, 1, $count);
        if ($next === null) {
            throw new RuntimeException('could not update LICENSE.txt');
        }
        $this->write($file, $count === 0 ? $line . "\n\n" . $text : $next);
    }

    public function updateComposer(string $file, string $license, string $phpFloor): void
    {
        $json = json_decode($this->read($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('composer.json is not an object');
        }
        $json['license'] = $license === 'Proprietary' ? 'proprietary' : $license;
        if ($license === 'Proprietary') {
            $json['private'] = true;
        } else {
            unset($json['private']);
        }
        if ($phpFloor !== '') {
            $json['require']['php'] = ">={$phpFloor}";
        }
        $this->write($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function alignPhpFloor(string $composerFile, string $infoFile, string $phpstanFile): void
    {
        $composer = json_decode($this->read($composerFile), true, 512, JSON_THROW_ON_ERROR);
        $constraint = is_array($composer) ? ($composer['require']['php'] ?? '') : '';
        if (!is_string($constraint) || preg_match('/^>=\s*(\d+\.\d+)$/', $constraint, $match) !== 1) {
            throw new RuntimeException('composer require.php must be a simple >=major.minor floor');
        }
        $floor = $match[1];
        $xml = $this->xml($infoFile);
        $versions = (new DOMXPath($xml))->query('/extension/php_compatibility/ver');
        if ($versions === false || $versions->length === 0) {
            throw new RuntimeException('info.xml has no PHP compatibility list');
        }
        $kept = 0;
        for ($index = $versions->length - 1; $index >= 0; $index--) {
            $version = $versions->item($index);
            if ($version !== null && version_compare(trim($version->textContent), $floor, '<')) {
                $version->parentNode?->removeChild($version);
            } else {
                $kept++;
            }
        }
        if ($kept === 0) {
            throw new RuntimeException("PHP floor {$floor} is newer than the civix compatibility list");
        }
        if ($xml->save($infoFile) === false) {
            throw new RuntimeException('could not write info.xml');
        }
        [$major, $minor] = array_map('intval', explode('.', $floor));
        $versionId = sprintf('%d%02d00', $major, $minor);
        $next = preg_replace_callback('/^(\s*phpVersion:\s*)\d+$/m', static fn(array $match): string => $match[1] . $versionId,
            $this->read($phpstanFile), 1, $count);
        if ($next === null || $count !== 1) {
            throw new RuntimeException('phpstan.neon.dist needs one phpVersion value');
        }
        $this->write($phpstanFile, $next);
    }

    public function updatePolicy(string $scenarioLibrary, string $file, string $license, string $copyright): void
    {
        require_once $scenarioLibrary;
        $document = \ck_scenario_parse_yaml($file);
        $document['policy']['license'] = $license;
        $document['policy']['copyright'] = $copyright;
        $this->write($file, \ck_scenario_dump_yaml($document));
    }

    private function rewriteInfoLicense(string $file): void
    {
        $xml = $this->xml($file);
        $xpath = new DOMXPath($xml);
        $licenses = $xpath->query('/extension/license');
        if ($licenses === false || $licenses->length !== 1) {
            throw new RuntimeException('info.xml needs one <license>');
        }
        $licenses->item(0)->textContent = 'Proprietary';
        $urls = $xpath->query('/extension/urls/url[@desc="Licensing"]');
        if ($urls !== false) {
            foreach (iterator_to_array($urls) as $url) {
                $url->parentNode?->removeChild($url);
            }
        }
        if ($xml->save($file) === false) {
            throw new RuntimeException('could not write info.xml');
        }
    }

    private function xml(string $file): DOMDocument
    {
        $xml = new DOMDocument();
        $xml->preserveWhiteSpace = true;
        if (!@$xml->load($file) || !$xml->documentElement instanceof DOMElement) {
            throw new RuntimeException("could not parse {$file}");
        }
        return $xml;
    }

    private function read(string $file): string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("could not read {$file}");
        }
        return $contents;
    }

    private function write(string $file, string $contents): void
    {
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new RuntimeException("could not write {$file}");
        }
    }
}
