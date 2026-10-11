<?php

declare(strict_types=1);

namespace CiviKitchen\Mago;

use InvalidArgumentException;

/**
 * Rule settings, given as `--name=value` arguments on the extension host
 * command in mago.toml. A list is comma-separated; an unknown name is an error.
 */
final class Options
{
    private const KNOWN = ['disable', 'exclude', 'max-lines', 'external-actions', 'ignore-calls', 'sql-known-tables', 'route-catalog'];

    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        $values = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $m) !== 1 || !in_array($m[1], self::KNOWN, true)) {
                throw new InvalidArgumentException("civikitchen mago extension: unknown argument {$argument}");
            }
            $values[$m[1]] = $m[2];
        }

        return new self($values);
    }

    public function string(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException("civikitchen mago extension: --{$name} needs a positive integer, got {$value}");
        }

        return (int) $value;
    }

    /** @return ?list<string> null when the option is absent */
    public function list(string $name): ?array
    {
        $value = $this->values[$name] ?? null;

        return $value === null ? null : array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }
}
