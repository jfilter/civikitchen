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
    /** @var list<array{level: string, message: string}> */
    private array $results = [];

    public function ok(string $message): void
    {
        $this->results[] = ['level' => 'ok', 'message' => $message];
    }

    public function warn(string $message): void
    {
        $this->results[] = ['level' => 'warn', 'message' => $message];
    }

    public function fail(string $message): void
    {
        $this->results[] = ['level' => 'FAIL', 'message' => $message];
    }

    /** @return list<array{level: string, message: string}> */
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
}
