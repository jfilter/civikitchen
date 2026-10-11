<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Workspace;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Bans legacy hook implementations where CiviCRM has standard mixins. A hook
 * is a global function `<file>_<hook>` (case-insensitive), `<file>` from the
 * nearest info.xml; without one, any name ending in `_<hook>` counts.
 */
final class UseMixinsForStandardHooksRule implements Rule
{
    /** Legacy hook => guidance shown in the message. */
    private const LEGACY_HOOKS = [
        'civicrm_managed' => 'move managed entity definitions to managed/*.mgd.php and enable the mgd-php mixin',
        'civicrm_navigationMenu' => 'declare menu entries as managed Navigation records in managed/*.mgd.php and enable the mgd-php mixin',
        'civicrm_xmlMenu' => 'move routes to xml/Menu/*.xml and enable the menu-xml mixin',
        'civicrm_caseTypes' => 'move case types to xml/case/*.xml and enable the case-xml mixin',
        'civicrm_themes' => 'move theme definitions to *.theme.php and enable the theme-php mixin',
        'civicrm_alterSettingsFolders' => 'move setting metadata to settings/*.setting.php and enable the setting-php mixin',
        'civicrm_entityTypes' => 'move entity type definitions to *.entityType.php and enable entity-types-php@2.0.0',
        'civicrm_angularModules' => 'move Angular module metadata to ang/*.ang.php and enable the ang-php mixin',
    ];

    /** Ancestors a hook function may sit in: top level, a namespace, the function_exists() guard. */
    private const ALLOWED_ANCESTORS = [
        NodeKind::Program, NodeKind::Statement, NodeKind::Block, NodeKind::Namespace, NodeKind::NamespaceBody,
        NodeKind::NamespaceImplicitBody, NodeKind::If, NodeKind::IfBody, NodeKind::IfStatementBody, NodeKind::IfColonDelimitedBody,
    ];

    /** @var array<string, ?string> extension root => its info.xml `<file>` */
    private array $prefixByRoot = [];

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/use-mixins-for-standard-hooks',
            name: 'Use mixins for standard hooks',
            description: 'Hooks a standard mixin replaces are not implemented by hand.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Program],
        );
    }

    public function lint(LintContext $context): void
    {
        // A Function target would see no ancestors outside its own subtree, so walk the file.
        foreach ($context->file->getDescendants($context->node, NodeKind::Function) as $function) {
            $this->check($context, $function);
        }
    }

    private function check(LintContext $context, Node $function): void
    {
        $source = $context->file;
        foreach ($source->getAncestors($function) as $ancestor) {
            if (!in_array($ancestor->kind, self::ALLOWED_ANCESTORS, true)) {
                return;
            }
        }
        $children = $source->getChildren($function);
        $keyword = null;
        $name = null;
        foreach ($children as $child) {
            if ($child->kind === NodeKind::Keyword && $keyword === null) {
                $keyword = $child;
            } elseif ($child->kind === NodeKind::LocalIdentifier) {
                $name = $child;
                break;
            }
        }
        // Core calls only the global function.
        if ($keyword === null || $name === null || str_contains((string) $source->getResolvedName($name)?->name, '\\')) {
            return;
        }

        $functionName = $source->getText($name);
        $prefix = $this->extensionPrefix(Workspace::extensionRoot($source));
        foreach (self::LEGACY_HOOKS as $hook => $guidance) {
            $matches = $prefix === null
                ? strcasecmp(substr($functionName, -strlen($hook) - 1), '_' . $hook) === 0
                : strcasecmp($functionName, $prefix . '_' . $hook) === 0;
            if ($matches) {
                $context->report(Issue::new(
                    sprintf('Legacy hook %s() is banned in modern extensions — %s', $functionName, $guidance),
                    $keyword->span,
                ));
                return;
            }
        }
    }

    private function extensionPrefix(?string $root): ?string
    {
        if ($root === null) {
            return null;
        }
        if (array_key_exists($root, $this->prefixByRoot)) {
            return $this->prefixByRoot[$root];
        }
        $internalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($root . '/info.xml', 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        $file = $xml === false ? '' : trim((string) $xml->file);

        return $this->prefixByRoot[$root] = $file === '' ? null : $file;
    }
}
