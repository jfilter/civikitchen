<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use PhpToken;

/**
 * Drops deprecation findings inside code marked `@ck-legacy`: a named
 * function or method, or the class, trait, interface or enum around it.
 */
final class CkLegacyFilterHook implements IssueFilterHook
{
    /** Exact tag: `@ck-legacy-ish` or `@ck-legacyfoo` must not match. */
    private const MARKER_PATTERN = '/@ck-legacy(?![\w-])/';

    private const DECLARATIONS = [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM];

    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];

    /** @var array<string, list<array{int, int}>> content hash => byte ranges of marked bodies */
    private array $ranges = [];

    public function getCodes(): array
    {
        return ['deprecated-class', 'deprecated-closure', 'deprecated-constant', 'deprecated-function', 'deprecated-method', 'deprecated-trait'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $offset = $context->issue->annotations[0]->span->start ?? null;
        if ($offset === null) {
            return IssueFilterDecision::Keep;
        }
        $key = md5($context->contents);
        $this->ranges[$key] ??= self::markedRanges($context->contents);
        foreach ($this->ranges[$key] as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return IssueFilterDecision::Remove;
            }
        }

        return IssueFilterDecision::Keep;
    }

    /**
     * Byte ranges of the bodies whose declaration carries the marker in its
     * doc comment; modifiers and attributes may stand between the two.
     *
     * @return list<array{int, int}>
     */
    private static function markedRanges(string $contents): array
    {
        $ranges = [];
        $stack = [];
        $doc = null;
        $pending = null;
        $attributeDepth = 0;
        $previous = null;
        foreach (PhpToken::tokenize($contents) as $token) {
            if ($token->isIgnorable() && !$token->is(T_DOC_COMMENT)) {
                continue;
            }
            if ($attributeDepth > 0) {
                $attributeDepth += match ($token->text) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };
                continue;
            }
            if ($token->is(T_ATTRIBUTE)) {
                $attributeDepth = 1;
                continue;
            }
            if ($token->is(T_DOC_COMMENT)) {
                $doc = $token->text;
                continue;
            }
            if ($token->is(self::DECLARATIONS) && $previous?->is(T_DOUBLE_COLON) !== true) {
                $pending = $doc !== null && preg_match(self::MARKER_PATTERN, $doc) === 1;
            }
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $stack[] = [$token->pos, $pending === true];
                $pending = null;
            } elseif ($token->text === '}') {
                [$start, $marked] = array_pop($stack) ?? [0, false];
                if ($marked) {
                    $ranges[] = [$start, $token->pos + 1];
                }
            } elseif ($token->text === ';') {
                $pending = null;
            }
            if (!$token->is(self::MODIFIERS)) {
                $doc = null;
            }
            $previous = $token;
        }

        return $ranges;
    }
}
