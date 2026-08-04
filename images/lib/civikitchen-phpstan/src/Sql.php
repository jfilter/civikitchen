<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;

/**
 * Recognising direct database traffic in the AST.
 *
 * Shared by the two rules that care about it: DDL inside a transactional
 * test, and a catch block that swallows a query failure. Both work on plain
 * literals and resolved names — the point is to be certain or silent, so an
 * expression this cannot read is simply not database traffic as far as the
 * rules are concerned.
 */
final class Sql
{
    /** DAO entry points that put a statement on the wire. */
    private const DAO_QUERY_METHODS = [
        'executequery', 'singlevaluequery', 'executeunbufferedquery', 'executeswitchquery',
        'query', 'executeconstantquery',
    ];

    /** Statements MySQL commits implicitly, ending any open transaction. */
    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    /** Does this call reach the database directly, bypassing APIv4? */
    public static function isDatabaseCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return self::isDaoClass(self::staticClassName($node))
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), self::DAO_QUERY_METHODS, true);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            // $dao->query(...) / $dao->find(TRUE) on a DAO instance variable.
            return $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), ['query', 'find', 'fetch'], true)
                && self::looksLikeDaoVariable($node->var);
        }

        return false;
    }

    /** A DDL statement that would commit an open test transaction. */
    public static function isDdlLiteral(string $sql): bool
    {
        $trimmed = ltrim($sql);
        foreach (self::DDL_KEYWORDS as $keyword) {
            if (preg_match('/^' . $keyword . '\b/i', $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The resolved class name of a static call, uppercase-insensitive. */
    public static function staticClassName(Node\Expr\StaticCall $node): ?string
    {
        return $node->class instanceof Node\Name ? $node->class->toString() : null;
    }

    /** CRM_Core_DAO itself, or a generated DAO/BAO class. */
    public static function isDaoClass(?string $class): bool
    {
        if ($class === null) {
            return false;
        }
        $class = ltrim($class, '\\');

        return $class === 'CRM_Core_DAO'
            || preg_match('/^CRM_[A-Za-z0-9]+_(DAO|BAO)_/', $class) === 1;
    }

    /**
     * Whether an expression is plausibly a DAO object.
     *
     * Only a `new CRM_..._DAO_...` or a variable named like one; anything
     * else would make `->find()` on an arbitrary object database traffic.
     */
    private static function looksLikeDaoVariable(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\New_) {
            return $expr->class instanceof Node\Name && self::isDaoClass($expr->class->toString());
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return preg_match('/dao$/i', $expr->name) === 1;
        }

        return false;
    }
}
