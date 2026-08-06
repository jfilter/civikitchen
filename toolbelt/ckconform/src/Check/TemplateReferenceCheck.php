<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A Page/Form class whose Smarty template the extension does not ship.
 *
 * CRM_Core_Page::getTemplateFileName() derives the template from the class name
 * — CRM_Foo_Page_Bar renders templates/CRM/Foo/Page/Bar.tpl — and Smarty raises
 * an unrecoverable error when that file is absent. Nothing loads the template at
 * install time, so the gap only shows up as a fatal for the first user who opens
 * the page. Forgetting the .tpl in `git add`, or renaming the class without the
 * template, gives precisely that.
 *
 * A class that overrides getTemplateFileName()/getTemplate(), or that extends
 * another of the extension's own Page/Form classes (and so inherits a template
 * that is checked at its own definition), is exempt. So is a class that
 * overrides run() without ever calling parent::run(): the parent's run() is
 * the only path that feeds the derived template to Smarty, so a redirect or
 * JSON endpoint never renders and needs no .tpl.
 */
final class TemplateReferenceCheck implements Check
{
    public function name(): string
    {
        return 'template-reference';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $namespaces = ExtensionNamespace::all($context);

        foreach ($context->sourceFiles('', ['.php']) as $relative) {
            if (preg_match('#^CRM/([A-Za-z0-9]+)/(?:Page|Form)/#', $relative, $match) !== 1) {
                continue;
            }
            if (!in_array(strtolower($match[1]), $namespaces, true)) {
                continue;
            }
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            $this->judgeClass($context, $reporter, $namespaces, $relative, $source);
        }

        foreach ($context->sourceFiles('', ['.php']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->literalTemplates($source) as $template) {
                if (!$this->isOwnTemplate($template, $namespaces)) {
                    continue;
                }
                $expected = 'templates/' . $template;
                if (!$context->ships($expected)) {
                    $reporter->fail("$relative: renders '$template' but $expected is missing — Smarty fatals on render");
                }
            }
        }
    }

    /** @param list<string> $namespaces */
    private function judgeClass(
        Context $context,
        Reporter $reporter,
        array $namespaces,
        string $relative,
        string $source,
    ): void {
        if (preg_match('/\babstract\s+class\b/', $source) === 1) {
            return;
        }
        if (preg_match('/\bfunction\s+(?:getTemplateFileName|getTemplate)\s*\(/', $source) === 1) {
            return;
        }
        if (preg_match('/\bfunction\s+run\s*\(/', $source) === 1
            && preg_match('/\bparent::run\s*\(/', $source) !== 1
        ) {
            // run() is overridden and never delegates to the parent, so the
            // derived template is never handed to Smarty.
            return;
        }
        if (preg_match('/\bclass\s+[A-Za-z0-9_]+\s+extends\s+([A-Za-z0-9_\\\\]+)/', $source, $match) === 1
            && ExtensionNamespace::isOwnClass($match[1], $namespaces)
        ) {
            // Inherits a template from a sibling class, which is judged there.
            return;
        }

        $expected = 'templates/' . preg_replace('/\.php$/', '.tpl', $relative);
        if (!$context->ships($expected)) {
            $reporter->fail("$relative: no template $expected — the page fatals in Smarty when rendered");
        }
    }

    /**
     * Literal '….tpl' arguments to a render call. 'string:' templates are inline
     * Smarty source, not files.
     *
     * @return list<string>
     */
    private function literalTemplates(string $source): array
    {
        $found = [];
        $pattern = '/(?:fetch|assign|assignTemplate|setTemplate|include)\s*\(\s*[^)\'"]*[\'"]([^\'"]+\.tpl)[\'"]/';
        if (preg_match_all($pattern, $source, $matches) !== false) {
            foreach ($matches[1] as $template) {
                if (!str_starts_with($template, 'string:')) {
                    $found[] = $template;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @param list<string> $namespaces */
    private function isOwnTemplate(string $template, array $namespaces): bool
    {
        return preg_match('#^CRM/([A-Za-z0-9]+)/#', $template, $match) === 1
            && in_array(strtolower($match[1]), $namespaces, true);
    }

}
