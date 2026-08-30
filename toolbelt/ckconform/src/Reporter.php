<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Collects results and renders them in the format the bash predecessor used,
 * byte for byte — the golden output captured across all consuming repos is the
 * regression net for this port, and it is only a net if the format matches.
 */
final class Reporter
{
    /** @var list<array{level: string, message: string, rule: string, location?: array{file: string, line: int}}> */
    private array $results = [];

    private string $rule = 'ckconform';

    public function setRule(string $rule): void
    {
        $this->rule = $rule;
    }

    public function ok(string $message): void
    {
        $this->add('ok', $message);
    }

    public function warn(string $message): void
    {
        $this->add('warn', $message);
    }

    public function fail(string $message): void
    {
        $this->add('FAIL', $message);
    }

    public function warnAt(string $file, int $line, string $message): void
    {
        $this->add('warn', $message, $file, $line);
    }

    public function failAt(string $file, int $line, string $message): void
    {
        $this->add('FAIL', $message, $file, $line);
    }

    /** @return list<array{level: string, message: string, rule: string, location?: array{file: string, line: int}}> */
    public function results(): array
    {
        return $this->results;
    }

    public function count(string $level): int
    {
        return count(array_filter($this->results, static fn (array $r): bool => $r['level'] === $level));
    }

    public function failures(): int
    {
        return $this->count('FAIL');
    }

    public function warnings(): int
    {
        return $this->count('warn');
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['message'],
            array_filter($this->results, static fn (array $r): bool => $r['level'] === $level),
        ));
    }

    public function render(): string
    {
        $lines = [];
        foreach ($this->results as $result) {
            // 'ok' is padded to the width of 'FAIL' so messages line up.
            $prefix = $result['level'] === 'ok' ? 'ok  ' : $result['level'];
            $lines[] = $prefix . ' ' . $result['message'];
        }
        $lines[] = '';
        $lines[] = sprintf('ckconform: %d failure(s), %d warning(s)', $this->failures(), $this->warnings());

        return implode("\n", $lines) . "\n";
    }

    public function renderJson(): string
    {
        return $this->json([
            'tool' => 'ckconform',
            'failures' => $this->failures(),
            'warnings' => $this->warnings(),
            'results' => $this->results,
        ]);
    }

    public function renderGithub(): string
    {
        $lines = [];
        foreach ($this->results as $result) {
            if ($result['level'] === 'ok') {
                continue;
            }
            $command = $result['level'] === 'FAIL' ? 'error' : 'warning';
            $properties = ['title=' . $this->githubEscapeProperty('ckconform/' . $result['rule'])];
            if (isset($result['location'])) {
                $properties[] = 'file=' . $this->githubEscapeProperty($result['location']['file']);
                $properties[] = 'line=' . $result['location']['line'];
            }
            $message = $this->githubEscapeData($result['message']);
            $lines[] = "::{$command} " . implode(',', $properties) . "::{$message}";
        }
        $lines[] = sprintf('ckconform: %d failure(s), %d warning(s)', $this->failures(), $this->warnings());

        return implode("\n", $lines) . "\n";
    }

    public function renderSarif(): string
    {
        $rules = [];
        $results = [];
        foreach ($this->results as $result) {
            if ($result['level'] === 'ok') {
                continue;
            }
            $rule = $result['rule'];
            $rules[$rule] = [
                'id' => $rule,
                'shortDescription' => ['text' => "ckconform {$rule}"],
            ];
            $entry = [
                'ruleId' => $rule,
                'level' => $result['level'] === 'FAIL' ? 'error' : 'warning',
                'message' => ['text' => $result['message']],
            ];
            if (isset($result['location'])) {
                $location = $result['location'];
                $entry['locations'] = [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->pathUri($location['file'])],
                        'region' => ['startLine' => $location['line']],
                    ],
                ]];
            }
            $results[] = $entry;
        }

        return $this->json([
            'version' => '2.1.0',
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'ckconform',
                    'informationUri' => 'https://github.com/jfilter/civikitchen',
                    'rules' => array_values($rules),
                ]],
                'results' => $results,
            ]],
        ]);
    }

    private function add(string $level, string $message, ?string $file = null, ?int $line = null): void
    {
        $result = ['level' => $level, 'message' => $message, 'rule' => $this->rule];
        if ($file !== null && $line !== null) {
            $result['location'] = ['file' => $file, 'line' => $line];
        }
        $this->results[] = $result;
    }

    private function githubEscapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function githubEscapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }

    private function pathUri(string $path): string
    {
        return str_replace('%2F', '/', rawurlencode($path));
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
