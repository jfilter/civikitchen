<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/** Token-level views of PHP source that a regex cannot provide. */
final class PhpSource
{
    /**
     * PHP source with comment bodies blanked, so an entity or call named only
     * in prose is not read as code. Through the tokenizer, since a regex cannot
     * tell a `//` inside a string from one that starts a comment. Newlines
     * inside comments are kept so line-based tooling still lines up.
     */
    public static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
