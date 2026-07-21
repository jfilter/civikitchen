<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Every tracked package.json must carry the npm licence the repo policy names
 * (`npm_license=` in `.ckconform`). npm publishes what the manifest says, not
 * what info.xml says, so a proprietary extension with a default `"ISC"` in a
 * nested build manifest is one `npm publish` away from being open source.
 *
 * Tracked rather than on-disk: an untracked manifest cannot be published by
 * anyone else. node_modules is excluded — vendored manifests are not ours.
 */
final class NpmLicenseCheck implements Check
{
    public function name(): string
    {
        return 'npm-license';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $want = $context->policyValue('npm_license');
        if ($want === null || !$context->isGitRepo()) {
            return;
        }

        $manifests = $context->tracked(
            '*package.json',
            static fn (string $file): bool => !str_contains($file, 'node_modules'),
        );

        foreach ($manifests as $manifest) {
            $have = $this->license($context, $manifest);
            if (strtolower($have) !== strtolower($want)) {
                $reporter->fail(sprintf(
                    "%s license is '%s', .ckconform expects '%s'",
                    $manifest,
                    $have === '' ? 'unset' : $have,
                    $want,
                ));
            }
        }
    }

    private function license(Context $context, string $manifest): string
    {
        $json = $context->json($manifest);
        $license = $json['license'] ?? null;

        return is_string($license) ? $license : '';
    }
}
