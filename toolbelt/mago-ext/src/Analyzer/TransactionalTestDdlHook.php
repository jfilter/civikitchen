<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\Sql;
use CiviKitchen\PHPStan\TransactionalDdl;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Schema work inside a test that runs in a transaction: MySQL commits on DDL,
 * so the rollback has nothing left to undo. See TransactionalTestDdlRule.
 */
final class TransactionalTestDdlHook implements ClassLikeAnalysisHook
{
    private const CUSTOM_FIELD = 'test-custom-field-in-transaction';

    private const DDL = 'test-ddl-in-transaction';

    private const EXTENSION = 'test-extension-in-transaction';

    public function getTargets(): array
    {
        return [ClassLikeTarget::descendantsOf(TransactionalDdl::TRANSACTIONAL_INTERFACE)];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        foreach (self::reachableFromTransaction($source, SqlNodes::methods($source, $context->node)) as $method) {
            foreach (SqlNodes::bodyNodes($source, $method, SqlNodes::CALLS) as $call) {
                $finding = self::check($source, $call);
                if ($finding !== null) {
                    $context->report(Level::Error, $finding[1], Issue::new(TransactionalDdl::message($finding[0]), $call->span));
                }
            }
        }
    }

    /**
     * setUp(), tearDown() and the test methods, plus the own helpers they call;
     * setUpHeadless() runs before the transaction opens.
     *
     * @param array<string, Node> $methods
     * @return list<Node>
     */
    private static function reachableFromTransaction(SourceFile $source, array $methods): array
    {
        $queue = array_values(array_filter(
            array_keys($methods),
            static fn (string $name): bool => $name === 'setup' || $name === 'teardown' || str_starts_with($name, 'test'),
        ));
        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !isset($methods[$name])) {
                continue;
            }
            $seen[$name] = $methods[$name];
            foreach (SqlNodes::bodyNodes($source, $methods[$name], [NodeKind::MethodCall, NodeKind::StaticMethodCall]) as $call) {
                $own = SqlNodes::ownCall($source, $call);
                if ($own !== null) {
                    $queue[] = $own;
                }
            }
        }

        return array_values($seen);
    }

    /** @return ?array{string, string} what and code */
    private static function check(SourceFile $source, Node $call): ?array
    {
        return match ($call->kind) {
            NodeKind::StaticMethodCall => self::checkStaticCall($source, $call),
            NodeKind::FunctionCall => self::checkFunctionCall($source, $call),
            default => self::checkMethodCall($source, $call),
        };
    }

    /** @return ?array{string, string} */
    private static function checkStaticCall(SourceFile $source, Node $call): ?array
    {
        $class = ltrim(SqlNodes::className($source, $call) ?? '', '\\');
        $method = SqlNodes::name($source, $call) ?? '';
        if (stripos($class, 'Civi\\Api4\\') === 0) {
            return self::customFieldWrite(substr($class, strlen('Civi\\Api4\\')), $method, '%s::%s()');
        }
        if (preg_match('/^CRM_Core_(BAO|DAO)_Custom(Field|Group)$/i', $class) === 1
            && in_array(strtolower($method), TransactionalDdl::DDL_BAO_METHODS, true)) {
            return [sprintf('%s::%s() in a transactional test', $class, $method), self::CUSTOM_FIELD];
        }

        return Sql::isDaoClass($class) ? self::checkSqlArguments($source, $call) : null;
    }

    /** @return ?array{string, string} */
    private static function checkFunctionCall(SourceFile $source, Node $call): ?array
    {
        $function = strtolower(ltrim(SqlNodes::name($source, $call) ?? '', '\\'));
        if (!in_array($function, ['civicrm_api4', 'civicrm_api3', 'civicrm_api'], true)) {
            return null;
        }
        $entity = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'entity'));
        $action = SqlNodes::literalString($source, SqlNodes::argument($source, $call, 1, 'action'));
        if ($entity === null || $action === null) {
            return null;
        }
        $write = self::customFieldWrite($entity, $action, $function . "('%s', '%s')");
        if ($write !== null) {
            return $write;
        }
        if (strcasecmp(str_replace('_', '', $entity), 'CustomGroup') === 0
            && in_array(strtolower($action), ['update', 'setvalue'], true) && self::apiSetsIsMultiple($source, $call, $function, $action)) {
            return [sprintf("%s('CustomGroup', '%s') setting is_multiple in a transactional test", $function, $action), self::CUSTOM_FIELD];
        }
        if (strcasecmp($entity, 'Extension') === 0 && in_array(strtolower($action), TransactionalDdl::EXTENSION_ACTIONS, true)) {
            return [sprintf("%s('Extension', '%s') in a transactional test", $function, $action), self::EXTENSION];
        }

        return null;
    }

    /** @return ?array{string, string} */
    private static function customFieldWrite(string $entity, string $action, string $callFormat): ?array
    {
        $ddlEntity = TransactionalDdl::ddlEntity($entity, $action);

        return $ddlEntity === null ? null : [sprintf($callFormat, $ddlEntity, $action) . ' in a transactional test', self::CUSTOM_FIELD];
    }

    /** @return ?array{string, string} */
    private static function checkMethodCall(SourceFile $source, Node $call): ?array
    {
        $method = strtolower(SqlNodes::name($source, $call) ?? '');
        if (in_array($method, ['addvalue', 'setvalues'], true) && self::fluentSetsIsMultiple($source, $call, $method)) {
            $root = SqlNodes::receiver($source, $call);
            while ($root->kind === NodeKind::MethodCall) {
                $root = SqlNodes::receiver($source, $root);
            }
            if ($root->kind === NodeKind::StaticMethodCall
                && strcasecmp(ltrim(SqlNodes::className($source, $root) ?? '', '\\'), 'Civi\\Api4\\CustomGroup') === 0
                && strtolower(SqlNodes::name($source, $root) ?? '') === 'update') {
                return ['CustomGroup::update() setting is_multiple in a transactional test', self::CUSTOM_FIELD];
            }
        }
        // Only an extension manager's install() runs an extension's SQL.
        if (in_array($method, TransactionalDdl::EXTENSION_ACTIONS, true) && self::isExtensionManager($source, SqlNodes::receiver($source, $call))) {
            return [sprintf('extension %s() in a transactional test', $method), self::EXTENSION];
        }

        return SqlNodes::isDatabaseCall($source, $call) ? self::checkSqlArguments($source, $call) : null;
    }

    /** @return ?array{string, string} */
    private static function checkSqlArguments(SourceFile $source, Node $call): ?array
    {
        foreach (SqlNodes::argumentValues($source, $call) as $value) {
            $sql = SqlNodes::literalString($source, $value);
            $verb = $sql === null ? null : TransactionalDdl::ddlVerb($sql);
            if ($verb !== null) {
                return [sprintf('%s statement in a transactional test', $verb), self::DDL];
            }
        }

        return null;
    }

    /** Flipping is_multiple swaps the custom-value table's unique index for a plain one. */
    private static function apiSetsIsMultiple(SourceFile $source, Node $call, string $function, string $action): bool
    {
        $params = SqlNodes::argument($source, $call, 2, 'params');
        if ($function === 'civicrm_api4' || self::scalarText($source, SqlNodes::arrayItem($source, $params, 'version')) === '4') {
            return SqlNodes::arrayItem($source, SqlNodes::arrayItem($source, $params, 'values'), 'is_multiple') !== null;
        }
        if (strtolower($action) === 'setvalue') {
            return SqlNodes::literalString($source, SqlNodes::arrayItem($source, $params, 'field')) === 'is_multiple';
        }

        return SqlNodes::arrayItem($source, $params, 'is_multiple') !== null;
    }

    /** `addValue('is_multiple', …)` or `setValues(['is_multiple' => …])`. */
    private static function fluentSetsIsMultiple(SourceFile $source, Node $call, string $method): bool
    {
        if ($method === 'addvalue') {
            return SqlNodes::literalString($source, SqlNodes::argument($source, $call, 0, 'fieldName')) === 'is_multiple';
        }

        return SqlNodes::arrayItem($source, SqlNodes::argument($source, $call, 0, 'values'), 'is_multiple') !== null;
    }

    /** The value of a plain string or integer literal. */
    private static function scalarText(SourceFile $source, ?Node $node): ?string
    {
        $literal = $node === null ? null : ($source->getChildren(SqlNodes::unwrap($source, $node))[0] ?? null);
        if ($literal?->kind === NodeKind::LiteralInteger) {
            return (string) intval(str_replace('_', '', $source->getText($literal)), 0);
        }

        return $literal?->kind === NodeKind::LiteralString ? SqlNodes::literalString($source, $node) : null;
    }

    private static function isExtensionManager(SourceFile $source, Node $receiver): bool
    {
        if ($receiver->kind === NodeKind::MethodCall) {
            return strtolower(SqlNodes::name($source, $receiver) ?? '') === 'getmanager';
        }
        $name = SqlNodes::variableName($source, $receiver);

        return $name !== null && preg_match('/manager$/i', $name) === 1;
    }
}
