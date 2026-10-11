<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\Api4Catalog;
use CiviKitchen\PHPStan\Api4Contract;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Field names passed to the fluent APIv4 builder, one function body at a
 * time: clauses may be spread over a chain and over statements on a variable,
 * and an alias may be selected after the orderBy that uses it.
 */
final class Api4FluentFieldHook implements NodeAnalysisHook
{
    /** Builder methods whose leading argument is a field name, with core's parameter name. */
    private const FIRST_ARG_IS_FIELD = [
        'addwhere' => 'fieldName', 'addvalue' => 'fieldName', 'addorderby' => 'fieldName', 'addgroupby' => 'field',
    ];

    /** Builder methods taking a whole clause array, with core's parameter name. */
    private const ARRAY_ARG = [
        'setselect' => 'select', 'setwhere' => 'where', 'setvalues' => 'values',
        'setorderby' => 'orderBy', 'setgroupby' => 'groupBy',
    ];

    /** Clauses where a select alias is a legal name. */
    private const ALIAS_CLAUSES = ['addorderby', 'addgroupby', 'setorderby', 'setgroupby'];

    public function getTargets(): array
    {
        return [NodeKind::Function, NodeKind::Method, NodeKind::Closure, NodeKind::ArrowFunction];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        foreach (FluentBuilders::of($source, $context->node, new CodebaseClassLookup($context->codebase)) as $builder) {
            if (!Api4Catalog::hasCompleteFields($builder['entity'])) {
                continue;
            }
            $aliases = $this->aliases($context, $builder['links']);
            $shadowed = $this->joinAliases($context, $builder['links']);
            foreach ($builder['links'] as $link) {
                foreach ($this->checkLink($context, $builder['entity'], $link, $aliases, $shadowed, $builder['escapes']) as $message) {
                    $context->report(Level::Error, 'api4-unknown-field', Issue::new($message, $link->span));
                }
            }
        }
    }

    /**
     * @param ?list<string> $aliases
     * @param ?list<string> $shadowed
     * @return list<string>
     */
    private function checkLink(NodeAnalysisContext $context, string $entity, Node $link, ?array $aliases, ?array $shadowed, bool $escapes): array
    {
        $name = FluentBuilders::methodName($context->source, $link);
        if ($name === null) {
            return [];
        }
        $method = strtolower($name);
        // A builder handed elsewhere, or a select not fully known, may hold any alias.
        if (($escapes || $aliases === null) && in_array($method, self::ALIAS_CLAUSES, true)) {
            return [];
        }
        // A join whose entity is not known may rebind any implicit name.
        $checkJoins = !$escapes && $shadowed !== null && !in_array($method, ['addvalue', 'setvalues'], true);

        $messages = [];
        $clause = $name . '()';
        foreach (array_diff($this->fieldsOf($context, $method, $link), $aliases ?? []) as $field) {
            if (str_contains($field, '.')) {
                if ($checkJoins) {
                    $messages[] = Api4Contract::joinFieldProblem($entity, $field, $clause, $shadowed);
                }
                continue;
            }
            $messages[] = Api4Contract::fieldProblem($entity, $field, $clause);
        }

        return array_values(array_filter($messages, 'is_string'));
    }

    /** @return list<string> */
    private function fieldsOf(NodeAnalysisContext $context, string $method, Node $link): array
    {
        $call = CallExpression::fromNode($context->source, $link);
        if (isset(self::FIRST_ARG_IS_FIELD[$method])) {
            $field = Types::literalString(Api4Calls::typeOf($context, Api4Calls::argument($call, 0, self::FIRST_ARG_IS_FIELD[$method])));

            return $field === null ? [] : [$field];
        }
        if ($method === 'addselect') {
            $fields = [];
            foreach ($call->arguments as $argument) {
                // addSelect(...$columns): a spread is not a name we know.
                $field = $argument->unpacked || $argument->name !== null ? null : Types::literalString(Api4Calls::typeOf($context, $argument->value));
                if ($field !== null) {
                    $fields[] = $field;
                }
            }

            return $fields;
        }
        $argument = isset(self::ARRAY_ARG[$method]) ? Api4Calls::argument($call, 0, self::ARRAY_ARG[$method]) : null;
        if ($argument === null) {
            return [];
        }
        $type = Api4Calls::typeOf($context, $argument);

        return match ($method) {
            'setwhere' => Types::fieldsFromWhere($type),
            'setselect', 'setgroupby' => Types::listOfStrings($type),
            default => Types::keys($type),
        };
    }

    /**
     * Aliases the builder's select defines (`SUM(x) AS total`); null when a
     * select argument is not fully known, so any alias may exist.
     *
     * @param list<Node> $links
     * @return ?list<string>
     */
    private function aliases(NodeAnalysisContext $context, array $links): ?array
    {
        $aliases = [];
        foreach ($links as $link) {
            $method = strtolower((string) FluentBuilders::methodName($context->source, $link));
            if (!in_array($method, ['addselect', 'setselect'], true)) {
                continue;
            }
            foreach (CallExpression::fromNode($context->source, $link)->arguments as $argument) {
                $strings = $argument->unpacked ? null : $this->selectStrings(Api4Calls::typeOf($context, $argument->value));
                if ($strings === null) {
                    return null;
                }
                foreach ($strings as $string) {
                    if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $string, $m) === 1) {
                        $aliases[] = $m[1];
                    }
                }
            }
        }

        return $aliases;
    }

    /** A select argument's strings: a literal, or a sealed array of literals. Null when not fully known. */
    private function selectStrings(?Type $type): ?array
    {
        $entries = Types::entries($type);
        if ($entries === null) {
            return Types::literalStrings($type);
        }
        $strings = [];
        foreach ($entries as $entry) {
            $values = Types::literalStrings($entry['value']);
            if ($values === null) {
                return null;
            }
            $strings = array_merge($strings, $values);
        }

        return $strings;
    }

    /**
     * Aliases an explicit join binds, which shadow an implicit join of the
     * same name. Null when a join's entity is not a known string.
     *
     * @param list<Node> $links
     * @return ?list<string>
     */
    private function joinAliases(NodeAnalysisContext $context, array $links): ?array
    {
        $aliases = [];
        foreach ($links as $link) {
            $method = strtolower((string) FluentBuilders::methodName($context->source, $link));
            $call = CallExpression::fromNode($context->source, $link);
            $entities = match ($method) {
                'addjoin' => [Api4Calls::typeOf($context, Api4Calls::argument($call, 0, 'entity'))],
                'setjoin' => $this->joinEntityTypes(Api4Calls::typeOf($context, Api4Calls::argument($call, 0, 'join'))),
                default => [],
            };
            foreach ($entities as $entity) {
                $strings = Types::literalStrings($entity);
                if ($strings === null || $strings === []) {
                    return null;
                }
                foreach ($strings as $string) {
                    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', trim($string), $m) === 1) {
                        $aliases[] = $m[2] ?? $m[1];
                    }
                }
            }
        }

        return $aliases;
    }

    /** The entity type of each join in `setJoin([[entity, …], …])`; a null entry when one is unknown. */
    private function joinEntityTypes(?Type $joins): array
    {
        $entries = Types::entries($joins);
        if ($entries === null) {
            return [null];
        }

        return array_map(static fn (array $join) => (Types::entries($join['value']) ?? [])[0]['value'] ?? null, $entries);
    }
}
