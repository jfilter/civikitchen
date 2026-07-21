<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * CRM.api3 in JS/Smarty.
 *
 * The NoLegacyCall sniff only reads PHP, so an APIv3 call in a template or a
 * bundle survives a whole migration unnoticed. Some entities genuinely have no
 * APIv4 form yet, so there is an escape hatch — but it costs a written reason,
 * like every other ignore in this toolchain.
 *
 * Tracked files only: an untracked scratch file cannot break anyone's build.
 * Vendored and generated front-end code is skipped, since nobody migrates a
 * minified bundle by hand.
 */
final class FrontEndApi3Check implements Check
{
    private const GLOBS = ['*.js', '*.tpl', '*.html', '*.vue'];

    public function name(): string
    {
        return 'front-end-api3';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach ($this->candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'CRM.api3')) {
                continue;
            }

            if ($this->hasDocumentedReason($contents)) {
                $reporter->ok("{$file} uses CRM.api3 with a documented reason");
            } else {
                $reporter->fail(
                    "{$file} calls CRM.api3 — migrate to CRM.api4, or annotate 'ck-allow-api3 -- <reason>'",
                );
            }
        }
    }

    /**
     * Sorted and de-duplicated so the output order matches `git ls-files`,
     * which the golden comparison depends on.
     *
     * @return list<string>
     */
    private function candidates(Context $context): array
    {
        $files = [];
        foreach (self::GLOBS as $glob) {
            foreach ($context->tracked($glob) as $file) {
                if ($this->isExcluded($file)) {
                    continue;
                }
                $files[$file] = true;
            }
        }
        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * Mirrors the bash exclusion exactly: 'node_modules' anywhere in the path,
     * '/dist/' with both slashes (so a top-level dist/ is *not* excluded), and
     * a .min.js suffix.
     */
    private function isExcluded(string $file): bool
    {
        return str_contains($file, 'node_modules')
            || str_contains($file, '/dist/')
            || str_ends_with($file, '.min.js');
    }

    /**
     * The annotation may sit anywhere in the file, but needs a non-empty reason
     * after the '--'. Matched line by line, because grep is line-based and a
     * whole-file match would let the reason come from the next line.
     */
    private function hasDocumentedReason(string $contents): bool
    {
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/ck-allow-api3[[:space:]]+--[[:space:]]*[^[:space:]]/', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
