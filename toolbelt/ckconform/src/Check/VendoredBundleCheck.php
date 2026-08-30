<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A third-party library copied into the repo — a `something.min.js` under
 * `js/lib/`, a `bower_components/` tree — is a dependency with the metadata
 * stripped off: no declared version, no lockfile integrity hash, no
 * Dependabot visibility, and a diff nobody can review. The template's answer
 * is package.json: declare the library as an exact-pinned dependency and let
 * packaging/deploy materialize node_modules.
 *
 * Only minified files are flagged. Readable third-party sources are at least
 * reviewable, and flagging every foreign-looking .js would drown the signal;
 * minification is the reliable marker of "built elsewhere".
 *
 * Files under node_modules/ and vendor/ are skipped here — a committed
 * node_modules is CommittedArtifactCheck's finding, and repeating each
 * contained file would bury it.
 */
final class VendoredBundleCheck implements Check
{
    private const BUNDLE_SUFFIXES = ['.min.js', '.min.css'];

    private const BUNDLE_DIRS = ['bower_components'];

    private const SKIP_DIRS = ['node_modules', 'vendor'];

    public function name(): string
    {
        return 'vendored-bundle';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        // Some repos ship bundles deliberately (e.g. no npm on the deploy
        // path). Declared in civikitchen.yaml: bundles=committed -- <reason>
        $policy = $context->policyValue('bundles');
        if ($policy !== null && str_starts_with($policy, 'committed')) {
            $reporter->ok("bundles committed — declared deliberate in civikitchen.yaml ({$policy})");

            return;
        }

        $flaggedDirs = [];
        foreach ($context->trackedFiles() as $file) {
            if ($this->isUnder($file, self::SKIP_DIRS)) {
                continue;
            }

            if ($this->isUnder($file, self::BUNDLE_DIRS)) {
                // One finding per directory; listing every contained file
                // would bury it.
                foreach (self::BUNDLE_DIRS as $dir) {
                    if ($this->isUnder($file, [$dir]) && !isset($flaggedDirs[$dir])) {
                        $flaggedDirs[$dir] = true;
                        $reporter->fail("vendored bundle committed: {$dir} — declare the libraries in package.json instead");
                    }
                }
                continue;
            }

            foreach (self::BUNDLE_SUFFIXES as $suffix) {
                if (str_ends_with($file, $suffix)) {
                    $reporter->fail("vendored bundle committed: {$file} — declare it in package.json and serve it from node_modules");
                }
            }
        }
    }

    /** @param list<string> $dirs */
    private function isUnder(string $file, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if (str_starts_with($file, "{$dir}/") || str_contains($file, "/{$dir}/")) {
                return true;
            }
        }

        return false;
    }
}
