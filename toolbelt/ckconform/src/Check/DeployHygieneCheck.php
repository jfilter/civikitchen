<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Extensions deploy via `git archive`: every tracked file lands in the
 * extension directory on the server, and that directory lives under the
 * CMS files/ tree — web-reachable by path. So "tracked" means "shipped",
 * and an external review found near-shipped secrets and PII exactly this
 * way. Three classes of file must therefore never be tracked:
 *
 *  - .env / .env.* (credentials; .env.example and .env.dist are templates)
 *  - anything under var/ (local working data)
 *  - documents and binary data files outside docs/, tests/, examples/
 *
 * A repo that genuinely needs to ship such a path declares it in its
 * civikitchen.yaml policy: deploy_hygiene=<path>[,<path>] -- <reason>
 */
final class DeployHygieneCheck implements Check
{
    private const ENV_TEMPLATES = ['.env.example', '.env.dist'];

    private const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'zip', 'sqlite', 'db', 'csv'];

    private const DOCUMENT_HOMES = ['docs/', 'tests/', 'examples/'];

    public function name(): string
    {
        return 'deploy_hygiene';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $allowed = $this->allowedPaths($context);

        foreach ($context->trackedFiles() as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $basename = basename($file);
            if (($basename === '.env' || str_starts_with($basename, '.env.'))
                && !in_array($basename, self::ENV_TEMPLATES, true)
            ) {
                $reporter->fail("deploy ships {$file} — git archive deploys every tracked file; keep credentials out of git ('.env.example'/'.env.dist' are fine)");
                continue;
            }

            if (str_starts_with($file, 'var/')) {
                $reporter->fail("deploy ships {$file} — var/ is local working data, not part of the extension; untrack it");
                continue;
            }

            if ($this->isStrayDocument($file)) {
                $reporter->fail("deploy ships {$file} — the extension directory under files/ is web-reachable; move documents/data files to docs/ (excluded from deploys)");
            }
        }
    }

    private function isStrayDocument(string $file): bool
    {
        foreach (self::DOCUMENT_HOMES as $home) {
            if (str_starts_with($file, $home)) {
                return false;
            }
        }
        $dot = strrpos($file, '.');
        if ($dot === false) {
            return false;
        }

        return in_array(strtolower(substr($file, $dot + 1)), self::DOCUMENT_EXTENSIONS, true);
    }

    /**
     * Paths a repo has declared deliberate, comma-separated, with the usual
     * `-- reason` suffix the civikitchen.yaml policy format carries.
     *
     * @return list<string>
     */
    private function allowedPaths(Context $context): array
    {
        $value = $context->policyValue('deploy_hygiene');
        if ($value === null) {
            return [];
        }
        $paths = explode(' -- ', $value, 2)[0];

        return array_values(array_filter(array_map('trim', explode(',', $paths))));
    }
}
