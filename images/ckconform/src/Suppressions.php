<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Inline suppressions, the eslint-style escape levels below the repo-wide
 * `.ckconform` policy (`ignore_checks=` there is the global one):
 *
 *   // ckconform-ignore <check-name> -- <reason>        (this line + the next)
 *   // ckconform-ignore-file <check-name> -- <reason>   (the whole file)
 *
 * Several checks may be listed comma-separated. The reason is not optional —
 * an ignore without one is itself reported (SuppressionHygieneCheck), same
 * doctrine as `tests=optional -- <reason>` in the policy file.
 *
 * Parsed from real comment tokens, so the marker in a string literal or a
 * heredoc does not suppress anything.
 */
final class Suppressions
{
    /** @var array<int, list<string>> line => check names suppressed there */
    private array $byLine = [];

    /** @var list<string> check names suppressed for the whole file */
    private array $fileWide = [];

    /** @var list<array{line: int, names: list<string>}> */
    private array $entries = [];

    /** @var list<int> lines carrying a marker without a reason */
    private array $missingReason = [];

    public static function of(string $contents): self
    {
        $self = new self();
        foreach (@token_get_all($contents) as $token) {
            if (!is_array($token) || !in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            [, $text, $line] = $token;
            if (!str_contains($text, 'ckconform-ignore')) {
                continue;
            }
            if (preg_match(
                '/ckconform-ignore(-file)?\s+([A-Za-z0-9-]+(?:\s*,\s*[A-Za-z0-9-]+)*)\s+--\s*(\S.*?)\s*(?:\*\/)?$/m',
                $text,
                $m,
            ) !== 1) {
                $self->missingReason[] = $line;
                continue;
            }
            $names = array_values(array_map('trim', explode(',', $m[2])));
            $self->entries[] = ['line' => $line, 'names' => $names];
            foreach ($names as $name) {
                if ($m[1] === '-file') {
                    $self->fileWide[] = $name;
                } else {
                    $self->byLine[$line][] = $name;
                    $self->byLine[$line + 1][] = $name;
                }
            }
        }

        return $self;
    }

    private function __construct()
    {
    }

    public function suppressed(string $check, int $line): bool
    {
        return in_array($check, $this->fileWide, true)
            || in_array($check, $this->byLine[$line] ?? [], true);
    }

    /**
     * Every ignore entry, for hygiene checks (unknown check names).
     *
     * @return list<array{line: int, names: list<string>}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Lines whose marker lacks the mandatory `-- <reason>`.
     *
     * @return list<int>
     */
    public function missingReason(): array
    {
        return $this->missingReason;
    }
}
