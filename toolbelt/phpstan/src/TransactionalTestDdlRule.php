<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Schema changes inside a test that runs in a transaction.
 *
 * `Civi\Test\TransactionalInterface` wraps every test method in a transaction
 * and rolls it back afterwards. MySQL commits implicitly on any DDL, so a
 * custom field, a custom group, an extension install or a raw CREATE/ALTER
 * inside setUp() or a test method silently ends that transaction: the
 * rollback then has nothing to roll back, the rows stay, and the next test —
 * or the next run — fails somewhere else entirely. That is the worst kind of
 * flake, because the file that caused it is green.
 *
 * The fix is never a suppression, it is moving the schema work into
 * setUpHeadless() (which runs before the transaction opens) or dropping
 * TransactionalInterface for that class.
 *
 * The whole class is read at once because the schema work is usually one
 * `$this->ensureTestSchema()` away from setUp() — checking method bodies in
 * isolation would see the call and not the DDL.
 *
 * @implements Rule<InClassNode>
 */
final class TransactionalTestDdlRule implements Rule
{
    private const TRANSACTIONAL_INTERFACE = 'Civi\\Test\\TransactionalInterface';

    /** APIv4 and APIv3 actions that run DDL on each entity above. */
    private const WRITE_ACTIONS = [
        'CustomField' => ['create', 'save', 'update', 'delete', 'replace', 'setvalue'],
        // An update alters the table only when it flips is_multiple, see namesIsMultiple().
        'CustomGroup' => ['create', 'save', 'delete', 'replace'],
    ];

    /** BAO entry points that create, alter or drop the custom-value table. */
    private const DDL_BAO_METHODS = [
        'create', 'writerecord', 'writerecords', 'deleterecord', 'deleterecords',
        'createtable', 'createfield', 'deletefield', 'deletegroup',
    ];

    private const EXTENSION_ACTIONS = ['install', 'enable', 'disable', 'uninstall'];

    private const ADVICE = 'MySQL commits implicitly on DDL, which ends the test transaction and leaves the rows behind — move it to setUpHeadless().';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::isTransactional($node->getClassReflection())) {
            return [];
        }

        /** @var array<string, Node\Stmt\ClassMethod> $methods */
        $methods = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->toLowerString()] = $stmt;
            }
        }

        $errors = [];
        foreach (self::reachableFromTransaction($methods) as $method) {
            foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr) as $expr) {
                $errors = array_merge($errors, self::check($expr));
            }
        }

        return $errors;
    }

    /**
     * setUp(), tearDown() and the test methods, plus the own helpers they
     * call through `$this->`, `self::` or `static::`.
     *
     * setUpHeadless() is deliberately not a root: it runs before the
     * transaction opens, and is where this rule wants the schema work to end
     * up.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return list<Node\Stmt\ClassMethod>
     */
    private static function reachableFromTransaction(array $methods): array
    {
        $queue = [];
        foreach ($methods as $name => $method) {
            if ($name === 'setup' || $name === 'teardown' || str_starts_with($name, 'test')) {
                $queue[] = $name;
            }
        }

        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !isset($methods[$name])) {
                continue;
            }
            $seen[$name] = $methods[$name];
            foreach ((new NodeFinder())->find($methods[$name]->stmts ?? [], static fn (Node $n): bool => self::isOwnCall($n)) as $call) {
                if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier) {
                    $queue[] = $call->name->toLowerString();
                }
            }
        }

        return array_values($seen);
    }

    /** `$this->x()`, `self::x()` or `static::x()`. */
    private static function isOwnCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\MethodCall) {
            return $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
        }

        return $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && in_array($node->class->toLowerString(), ['self', 'static'], true);
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function check(Node $expr): array
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            return self::checkStaticCall($expr);
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            return self::checkFuncCall($expr);
        }
        if ($expr instanceof Node\Expr\MethodCall) {
            return self::checkMethodCall($expr);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkStaticCall(Node\Expr\StaticCall $expr): array
    {
        $class = ltrim(Sql::staticClassName($expr) ?? '', '\\');
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

        // \Civi\Api4\CustomField::create()
        if (stripos($class, 'Civi\\Api4\\') === 0) {
            $entity = substr($class, strlen('Civi\\Api4\\'));

            return self::customFieldWrite($entity, $method, '%s::%s()', $expr);
        }

        // CRM_Core_BAO_CustomField::create() is what the API runs underneath.
        if (preg_match('/^CRM_Core_(BAO|DAO)_Custom(Field|Group)$/i', $class) === 1
            && in_array(strtolower($method), self::DDL_BAO_METHODS, true)) {
            return [self::error(
                sprintf('%s::%s() in a transactional test', $class, $method),
                'ck.test.customFieldInTransaction',
                $expr,
            )];
        }

        if (Sql::isDaoClass($class)) {
            return self::checkSqlArguments($expr->getArgs(), $expr);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkFuncCall(Node\Expr\FuncCall $expr): array
    {
        if (!$expr->name instanceof Node\Name) {
            return [];
        }
        $function = strtolower(ltrim($expr->name->toString(), '\\'));
        if (!in_array($function, ['civicrm_api4', 'civicrm_api3', 'civicrm_api'], true)) {
            return [];
        }
        [$entity, $action] = Sql::apiEntityAndAction($expr);
        if ($entity === null || $action === null) {
            return [];
        }
        $write = self::customFieldWrite($entity, $action, $function . "('%s', '%s')", $expr);
        if ($write !== []) {
            return $write;
        }
        if (strcasecmp(str_replace('_', '', $entity), 'CustomGroup') === 0
            && in_array(strtolower($action), ['update', 'setvalue'], true) && self::namesIsMultiple($expr)) {
            return [self::error(
                sprintf("%s('CustomGroup', '%s') setting is_multiple in a transactional test", $function, $action),
                'ck.test.customFieldInTransaction',
                $expr,
            )];
        }
        if (strcasecmp($entity, 'Extension') === 0 && in_array(strtolower($action), self::EXTENSION_ACTIONS, true)) {
            return [self::error(
                sprintf("%s('Extension', '%s') in a transactional test", $function, $action),
                'ck.test.extensionInTransaction',
                $expr,
            )];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function customFieldWrite(string $entity, string $action, string $callFormat, Node $expr): array
    {
        // Names resolve case-insensitively, and APIv3 also takes `custom_field`.
        $normalised = str_replace('_', '', $entity);
        $ddlEntity = array_values(array_filter(array_keys(self::WRITE_ACTIONS), static fn (string $e): bool => strcasecmp($e, $normalised) === 0))[0] ?? null;
        if ($ddlEntity === null || !in_array(strtolower($action), self::WRITE_ACTIONS[$ddlEntity], true)) {
            return [];
        }

        return [self::error(
            sprintf($callFormat, $ddlEntity, $action) . ' in a transactional test',
            'ck.test.customFieldInTransaction',
            $expr,
        )];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkMethodCall(Node\Expr\MethodCall $expr): array
    {
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toLowerString() : '';

        if (in_array($method, ['addvalue', 'setvalues'], true) && self::namesIsMultiple($expr)) {
            $root = $expr->var;
            while ($root instanceof Node\Expr\MethodCall) {
                $root = $root->var;
            }
            if ($root instanceof Node\Expr\StaticCall && $root->name instanceof Node\Identifier
                && strcasecmp(ltrim(Sql::staticClassName($root) ?? '', '\\'), 'Civi\\Api4\\CustomGroup') === 0
                && $root->name->toLowerString() === 'update') {
                return [self::error('CustomGroup::update() setting is_multiple in a transactional test', 'ck.test.customFieldInTransaction', $expr)];
            }
        }

        // An extension manager is the only thing in a test whose install()
        // means "run this extension's SQL"; the receiver has to say so.
        if (in_array($method, self::EXTENSION_ACTIONS, true) && self::isExtensionManager($expr->var)) {
            return [self::error(
                sprintf('extension %s() in a transactional test', $method),
                'ck.test.extensionInTransaction',
                $expr,
            )];
        }

        if (Sql::isDatabaseCall($expr)) {
            return self::checkSqlArguments($expr->getArgs(), $expr);
        }

        return [];
    }

    /**
     * @param  array<Node\Arg>                              $args
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkSqlArguments(array $args, Node $expr): array
    {
        foreach ($args as $arg) {
            $sql = self::literal($arg->value);
            if ($sql !== null && Sql::isDdlLiteral($sql)) {
                return [self::error(
                    sprintf('%s statement in a transactional test', strtoupper(strtok(ltrim($sql), " \n\t") ?: 'DDL')),
                    'ck.test.ddlInTransaction',
                    $expr,
                )];
            }
        }

        return [];
    }

    /** The class implements TransactionalInterface, directly or inherited. */
    /** Flipping is_multiple swaps the custom-value table's unique index for a plain one. */
    private static function namesIsMultiple(Node\Expr\CallLike $call): bool
    {
        return (new NodeFinder())->findFirst(
            $call->getArgs(),
            static fn (Node $node): bool => $node instanceof Node\Scalar\String_ && $node->value === 'is_multiple',
        ) !== null;
    }

        private static function isTransactional(ClassReflection $class): bool
    {
        foreach ($class->getNativeReflection()->getInterfaceNames() as $interface) {
            if ($interface === self::TRANSACTIONAL_INTERFACE) {
                return true;
            }
        }

        return false;
    }

    private static function literal(Node\Expr $expr): ?string
    {
        return Sql::literalString($expr);
    }

    private static function isExtensionManager(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            return $expr->name instanceof Node\Identifier
                && $expr->name->toLowerString() === 'getmanager';
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return preg_match('/manager$/i', $expr->name) === 1;
        }

        return false;
    }

    private static function error(string $what, string $identifier, Node $node): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message($what . ' — ' . self::ADVICE)
            ->identifier($identifier)
            ->line($node->getStartLine())
            ->build();
    }
}
