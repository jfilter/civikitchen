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

    /** Entities whose create/save writes a column, not a row. */
    private const DDL_ENTITIES = ['CustomField', 'CustomGroup'];

    private const WRITE_ACTIONS = ['create', 'save'];

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
     * setUp() and the test methods, plus the own helpers they call.
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
            if ($name === 'setup' || str_starts_with($name, 'test')) {
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
            foreach ((new NodeFinder())->find($methods[$name]->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall) as $call) {
                if (!$call instanceof Node\Expr\MethodCall || !$call->name instanceof Node\Identifier) {
                    continue;
                }
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $queue[] = $call->name->toLowerString();
                }
            }
        }

        return array_values($seen);
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
        if (str_starts_with($class, 'Civi\\Api4\\')) {
            $entity = substr($class, strlen('Civi\\Api4\\'));
            if (in_array($entity, self::DDL_ENTITIES, true) && in_array($method, self::WRITE_ACTIONS, true)) {
                return [self::error(
                    sprintf('%s::%s() in a transactional test', $entity, $method),
                    'ck.test.customFieldInTransaction',
                    $expr,
                )];
            }

            return [];
        }

        // CRM_Extension_System::singleton()->getManager()->install(...) starts here.
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
        $args = $expr->getArgs();
        $entity = isset($args[0]) ? self::literal($args[0]->value) : null;
        $action = isset($args[1]) ? self::literal($args[1]->value) : null;
        if ($entity === null || $action === null) {
            return [];
        }
        if (in_array($entity, self::DDL_ENTITIES, true) && in_array($action, self::WRITE_ACTIONS, true)) {
            return [self::error(
                sprintf("%s('%s', '%s') in a transactional test", $function, $entity, $action),
                'ck.test.customFieldInTransaction',
                $expr,
            )];
        }
        if ($entity === 'Extension' && in_array($action, self::EXTENSION_ACTIONS, true)) {
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
    private static function checkMethodCall(Node\Expr\MethodCall $expr): array
    {
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toLowerString() : '';

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
