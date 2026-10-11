<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter\Docblocks;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;

/**
 * A `/** … *\/` comment and its tags, read like phpcs tokenizes one: a tag
 * is an `@name` opening a line, its string the rest of that line.
 */
final class Docblock
{
    /** @param list<array{name: string, span: Span, string: string, stringSpan: ?Span}> $tags */
    private function __construct(public readonly Span $span, public readonly array $tags)
    {
    }

    /** @return list<self> every docblock of the file, in source order */
    public static function all(SourceFile $source): array
    {
        $docblocks = [];
        foreach ($source->getTrivia() as $trivia) {
            if ($trivia->kind === TriviaKind::DocBlockComment) {
                $docblocks[] = self::parse($source, $trivia->span);
            }
        }

        return $docblocks;
    }

    /**
     * The docblock directly above $offset: nothing but code without `;`, `{`
     * or `}` between them (modifiers, types, attributes).
     */
    public static function above(SourceFile $source, int $offset): ?self
    {
        $found = null;
        foreach (self::all($source) as $docblock) {
            if ($docblock->span->end <= $offset) {
                $found = $docblock;
            }
        }
        if ($found === null) {
            return null;
        }
        $between = substr($source->contents, $found->span->end, $offset - $found->span->end);

        return strpbrk($between, ';{}') === false ? $found : null;
    }

    private static function parse(SourceFile $source, Span $span): self
    {
        $text = $source->getText($span);
        $tags = [];
        $offset = 0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('~^(\s*(?:/\*\*|\*)?\s*)(@[^\s(]+)~', $line, $m) === 1) {
                $start = $span->start + $offset + strlen($m[1]);
                $rest = substr($line, strlen($m[1]) + strlen($m[2]));
                $rest = preg_replace('~\*/\s*$~', '', $rest);
                $string = trim($rest);
                $stringStart = $start + strlen($m[2]) + (strlen($rest) - strlen(ltrim($rest)));
                $tags[] = [
                    'name' => $m[2],
                    'span' => new Span($start, $start + strlen($m[2])),
                    'string' => $string,
                    'stringSpan' => $string === '' ? null : new Span($stringStart, $stringStart + strlen($string)),
                ];
            }
            $offset += strlen($line) + 1;
        }

        return new self($span, $tags);
    }
}
